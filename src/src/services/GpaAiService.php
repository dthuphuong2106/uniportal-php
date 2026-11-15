<?php
/**
 * UniPortal – GPA AI Service
 *
 * Hybrid narrative layer:
 *   * GPA math and strategy always come from GpaAdvisor (reliable backend).
 *   * When Admin configures an OpenAI-compatible endpoint, the calculated
 *     facts are sent to the provider for natural-language interpretation.
 *   * With no provider configured, a transparent rule-based narrative is
 *     returned and labelled as such in the UI. No fake AI output is produced.
 *
 * Privacy: only aggregated academic facts are sent – never name, student ID,
 * email, password or authentication tokens.
 */

require_once __DIR__ . '/SystemConfig.php';

class GpaAiService {

    public static function isConfigured(): bool {
        return SystemConfig::get('ai_enabled', '0') === '1'
            && trim(SystemConfig::get('ai_api_url', '')) !== '';
    }

    public static function modelName(): string {
        return trim(SystemConfig::get('ai_model', ''));
    }

    public static function sourceLabel(string $source): string {
        return match ($source) {
            'ai' => 'AI đã kết nối',
            'rule_based_fallback' => 'AI không phản hồi – dùng phân tích quy tắc',
            default => 'Phân tích dựa trên quy tắc (chưa kết nối AI API)',
        };
    }

    /**
     * @return array{source:string, label:string, text:string, model:?string}
     */
    public static function narrate(array $facts): array {
        $ruleText = self::ruleBased($facts);

        if (!self::isConfigured()) {
            return [
                'source' => 'rule_based',
                'label'  => self::sourceLabel('rule_based'),
                'text'   => $ruleText,
                'model'  => null,
            ];
        }

        $aiText = self::callProvider($facts);
        if ($aiText === null || $aiText === '') {
            return [
                'source' => 'rule_based_fallback',
                'label'  => self::sourceLabel('rule_based_fallback'),
                'text'   => $ruleText,
                'model'  => null,
            ];
        }

        return [
            'source' => 'ai',
            'label'  => self::sourceLabel('ai'),
            'text'   => $aiText,
            'model'  => self::modelName(),
        ];
    }

    // ─────────────────────────────────────────────────────────
    // Rule-based narrative (always available)
    // ─────────────────────────────────────────────────────────

    public static function ruleBased(array $f): string {
        $fmt = static fn($v) => $v === null ? '—' : number_format((float)$v, 2);
        $gpa = $fmt($f['current_gpa'] ?? null);

        $feasText = [
            'achieved' => 'bạn đã đạt mục tiêu này',
            'achievable' => 'mục tiêu khả thi với kế hoạch học tập hợp lý',
            'difficult' => 'mục tiêu khó, cần nỗ lực cao và kỷ luật học tập',
            'very_difficult' => 'mục tiêu rất khó, đòi hỏi GPA gần mức tối đa ở các kỳ còn lại',
            'unachievable' => 'mục tiêu không khả thi nếu chỉ dựa vào các tín chỉ còn lại',
            'no_future' => 'không còn tín chỉ kế hoạch để cải thiện GPA',
            'no_data' => 'chưa có dữ liệu điểm chính thức',
        ][$f['feasibility'] ?? 'no_data'] ?? 'chưa xác định';

        $parts = [];

        $parts[] = 'TỔNG QUAN: GPA tích lũy hiện tại là ' . $gpa . '/4,00 ('
            . ($f['classification'] ?? '—') . '), tính trên ' . (int)($f['gpa_credits'] ?? 0)
            . ' tín chỉ được tính GPA. Bạn đã hoàn thành ' . (int)($f['graduation_credits'] ?? 0)
            . '/' . (int)($f['required_credits'] ?? 0) . ' tín chỉ và còn '
            . (int)($f['remaining_credits'] ?? 0) . ' tín chỉ.';

        $parts[] = 'MỤC TIÊU ' . $fmt($f['target_gpa'] ?? null) . ': ' . $feasText . '.';
        $reqDisplay = ($f['required_future_raw'] ?? null) !== null && $f['required_future_raw'] > 4.0
            ? '> 4,00' : $fmt($f['required_future_gpa'] ?? null);
        if (($f['feasibility'] ?? '') !== 'achieved' && ($f['required_future_gpa'] ?? null) !== null) {
            $parts[] = 'TÍNH TOÁN: để đạt mục tiêu, GPA trung bình cần đạt trên các tín chỉ còn lại là '
                . $reqDisplay . '/4,00. Nếu tất cả môn còn lại đạt A+ (4,0), GPA tối đa có thể đạt là '
                . $fmt($f['max_possible_gpa']) . '/4,00.';
        } elseif (($f['feasibility'] ?? '') === 'achieved') {
            $parts[] = 'TÍNH TOÁN: mục tiêu đã đạt. Việc cần làm là duy trì GPA hiện tại; '
                . 'GPA tối đa có thể đạt nếu các môn còn lại đạt A+ là ' . $fmt($f['max_possible_gpa']) . '/4,00.';
        } else {
            $parts[] = 'TÍNH TOÁN: GPA tối đa có thể đạt nếu các môn còn lại đạt A+ là '
                . $fmt($f['max_possible_gpa']) . '/4,00.';
        }

        $strengths = $f['strengths'] ?? [];
        if (!empty($strengths)) {
            $items = array_map(static fn($s) => $s['code'] . ' ' . $s['letter']
                . ' (' . number_format((float)$s['point'], 1) . ')', $strengths);
            $parts[] = 'ĐIỂM MẠNH: ' . implode(', ', $items) . '.';
        }

        $weaknesses = $f['weaknesses'] ?? [];
        if (!empty($weaknesses)) {
            $items = array_map(static fn($w) => $w['code'] . ' ' . $w['letter']
                . ' (' . number_format((float)$w['point'], 1) . ')', $weaknesses);
            $parts[] = 'ĐIỂM CẦN CHÚ Ý: ' . implode(', ', $items) . '.';
        } else {
            $parts[] = 'ĐIỂM CẦN CHÚ Ý: không có môn nào dưới ngưỡng đạt trong hồ sơ hiện tại.';
        }

        $retakes = $f['retakes'] ?? [];
        $eligible = array_values(array_filter($retakes, static fn($r) => $r['priority'] !== 'Không đủ điều kiện'));
        if (empty($eligible)) {
            $parts[] = 'CẢI THIỆN ĐIỂM CŨ: không có môn nào cần ưu tiên cải thiện theo chính sách "'
                . ($f['retake_policy'] ?? '') . '".';
        } else {
            $required = array_values(array_filter($eligible, static fn($r) => !empty($r['required'])));
            if (!empty($required)) {
                $codes = implode(', ', array_map(static fn($r) => $r['code'], $required));
                $parts[] = 'CẢI THIỆN ĐIỂM CŨ: theo chính sách "' . ($f['retake_policy'] ?? '')
                    . '", cần ưu tiên cải thiện ' . $codes . ' để mục tiêu trở nên khả thi.';
            } else {
                $codes = implode(', ', array_map(static fn($r) => $r['code'], array_slice($eligible, 0, 3)));
                $parts[] = 'CẢI THIỆN ĐIỂM CŨ: mục tiêu vẫn khả thi nhờ các tín chỉ còn lại; '
                    . $codes . ' chỉ nên cải thiện nếu bạn muốn tăng GPA đệm.';
            }
        }

        $roadmap = $f['roadmap'] ?? [];
        if (!empty($roadmap)) {
            $first = $roadmap[0];
            $parts[] = 'LỘ TRÌNH: kỳ gần nhất dự kiến ' . (int)$first['credits'] . ' tín chỉ, GPA mục tiêu '
                . $fmt($first['target_gpa']) . '. Toàn bộ ' . count($roadmap) . ' kỳ còn lại được chia theo '
                . 'số tín chỉ thực tế còn thiếu.';
        }

        $parts[] = 'LƯU Ý: đây là phân tích dựa trên dữ liệu thực tế trong CSDL và chính sách hiện hành; '
            . 'các con số tương lai là ước tính, không phải kết quả chính thức.';

        return implode("\n\n", $parts);
    }

    // ─────────────────────────────────────────────────────────
    // Optional external provider (OpenAI-compatible)
    // ─────────────────────────────────────────────────────────

    private static function callProvider(array $facts): ?string {
        $url = trim(SystemConfig::get('ai_api_url', ''));
        if (!self::isSafeUrl($url)) return null;

        $key   = trim(SystemConfig::get('ai_api_key', ''));
        $model = self::modelName();
        if ($model === '') return null;

        // Strip anything that could identify the student before sending.
        unset($facts['student_id'], $facts['full_name'], $facts['email'], $facts['phone']);
        $factsJson = json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $payload = [
            'model'       => $model,
            'temperature' => 0.3,
            'max_tokens'  => 750,
            'messages'    => [
                [
                    'role' => 'system',
                    'content' => 'Bạn là cố vấn học vụ của một trường đại học. '
                        . 'Chỉ sử dụng dữ liệu JSON được cung cấp; tuyệt đối không bịa điểm, môn học, tín chỉ hay kết quả. '
                        . 'Trả lời bằng tiếng Việt, tối đa 250 từ, gồm 5 gạch đầu dòng ngắn: tổng quan, mục tiêu, '
                        . 'điểm mạnh/yếu, chiến lược cải thiện, lộ trình. Phân biệt rõ dữ liệu thực tế, kết quả tính toán, '
                        . 'khuyến nghị và dự báo. Không đưa ra cam kết về kết quả chính thức.',
                ],
                [
                    'role' => 'user',
                    'content' => "Dữ liệu học vụ đã được tính toán (không có thông tin cá nhân):\n" . $factsJson,
                ],
            ],
        ];

        $headers = ['Content-Type: application/json'];
        if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($raw) || $code < 200 || $code >= 300) return null;

        $data = json_decode($raw, true);
        $text = $data['choices'][0]['message']['content']
            ?? $data['choices'][0]['text']
            ?? null;
        if (!is_string($text)) return null;

        $text = strip_tags($text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? '';
        $text = trim(mb_substr($text, 0, 4000, 'UTF-8'));
        return $text !== '' ? $text : null;
    }

    /** Basic SSRF guard: http(s) only, no loopback / private / link-local hosts. */
    private static function isSafeUrl(string $url): bool {
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return false;
        $parts = parse_url($url);
        if (!in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) return false;

        $host = strtolower($parts['host'] ?? '');
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) return false;

        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        return true;
    }
}
