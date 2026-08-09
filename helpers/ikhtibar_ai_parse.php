<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/ikhtibar_import.php';

function ikhtibar_ai_ocr_enabled(PDO $pdo): bool
{
    return (string) app_setting($pdo, 'ikhtibar_ai_ocr_enabled', '0') === '1'
        && ikhtibar_ai_api_key($pdo) !== '';
}

function ikhtibar_ai_api_key(PDO $pdo): string
{
    return trim((string) app_setting($pdo, 'ikhtibar_ai_api_key', ''));
}

function ikhtibar_ai_model(PDO $pdo): string
{
    $model = trim((string) app_setting($pdo, 'ikhtibar_ai_model', ''));

    return $model !== '' ? $model : 'gpt-4o-mini';
}

function ikhtibar_ai_base_url(PDO $pdo): string
{
    $url = trim((string) app_setting($pdo, 'ikhtibar_ai_base_url', ''));

    return $url !== '' ? rtrim($url, '/') : 'https://api.openai.com/v1';
}

/**
 * Rapikan teks OCR/teks bebas ke struct soal via LLM (OpenAI-compatible).
 *
 * @return array{pg:array<int,array<string,mixed>>,esai:array<int,array<string,mixed>>,errors:list<string>}
 */
function ikhtibar_ai_parse_soal_dari_teks(PDO $pdo, string $rawText, int $maxPg, int $maxEsai): array
{
    $empty = ['pg' => [], 'esai' => [], 'errors' => []];
    if (!ikhtibar_ai_ocr_enabled($pdo)) {
        return $empty;
    }
    $rawText = trim($rawText);
    if ($rawText === '') {
        return $empty;
    }

    $system = 'Anda parser soal ujian. Kembalikan HANYA JSON valid tanpa markdown. '
        . 'Jangan mengubah isi teks soal/opsi, hanya petakan ke struktur. '
        . 'Format: {"pg":[{"nomor":1,"teks":"...","a":"...","b":"...","c":"...","d":"...","e":null,"kunci":"A"}],'
        . '"esai":[{"nomor":1,"teks":"...","kunci":"...","bobot":100}]} '
        . 'Maks PG: ' . $maxPg . ', maks esai: ' . $maxEsai . '. '
        . 'Opsi a-d wajib jika PG. kunci huruf A-E.';

    $user = "Teks mentah OCR:\n\n" . mb_substr($rawText, 0, 12000);

    try {
        $jsonText = ikhtibar_ai_chat_completion($pdo, $system, $user);
    } catch (Throwable $e) {
        return ['pg' => [], 'esai' => [], 'errors' => ['AI: ' . $e->getMessage()]];
    }

    $decoded = json_decode($jsonText, true);
    if (!is_array($decoded)) {
        return ['pg' => [], 'esai' => [], 'errors' => ['AI: respons JSON tidak valid.']];
    }

    $out = ['pg' => [], 'esai' => [], 'errors' => []];
    foreach ((array) ($decoded['pg'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $nom = max(1, (int) ($item['nomor'] ?? 0));
        if ($nom > $maxPg) {
            continue;
        }
        $teks = trim((string) ($item['teks'] ?? ''));
        if ($teks === '') {
            continue;
        }
        $kunci = strtoupper(trim((string) ($item['kunci'] ?? '')));
        $out['pg'][$nom] = [
            'teks' => $teks,
            'a' => trim((string) ($item['a'] ?? '')) ?: null,
            'b' => trim((string) ($item['b'] ?? '')) ?: null,
            'c' => trim((string) ($item['c'] ?? '')) ?: null,
            'd' => trim((string) ($item['d'] ?? '')) ?: null,
            'e' => trim((string) ($item['e'] ?? '')) ?: null,
            'kunci' => in_array($kunci, ['A', 'B', 'C', 'D', 'E'], true) ? $kunci : null,
            'bobot' => 100.0,
        ];
    }
    foreach ((array) ($decoded['esai'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $nom = max(1, (int) ($item['nomor'] ?? 0));
        if ($nom > $maxEsai) {
            continue;
        }
        $teks = trim((string) ($item['teks'] ?? ''));
        if ($teks === '') {
            continue;
        }
        $out['esai'][$nom] = [
            'teks' => $teks,
            'kunci' => trim((string) ($item['kunci'] ?? '')) ?: null,
            'bobot' => max(1, min(100, (float) ($item['bobot'] ?? 100))),
        ];
    }

    $out['errors'] = ikhtibar_import_errors_dari_soal($out, $maxPg, $maxEsai);

    return $out;
}

function ikhtibar_ai_chat_completion(PDO $pdo, string $system, string $user): string
{
    $apiKey = ikhtibar_ai_api_key($pdo);
    if ($apiKey === '') {
        throw new RuntimeException('API key AI belum diisi.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL tidak tersedia.');
    }

    $payload = [
        'model' => ikhtibar_ai_model($pdo),
        'temperature' => 0.1,
        'response_format' => ['type' => 'json_object'],
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ],
    ];

    $ch = curl_init(ikhtibar_ai_base_url($pdo) . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 60,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code >= 400) {
        $err = is_string($body) ? $body : '';
        throw new RuntimeException('Permintaan AI gagal (HTTP ' . $code . '). ' . mb_substr($err, 0, 200));
    }

    $data = json_decode((string) $body, true);
    $content = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
    if ($content === '') {
        throw new RuntimeException('AI tidak mengembalikan konten.');
    }

    return $content;
}
