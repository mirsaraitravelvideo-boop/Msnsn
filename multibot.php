<?php
// multibot.php — Multi-Bot API Helper
// টোকেন rotate করে Telegram API কল করে

$BOT_TOKENS = require __DIR__ . "/tokens.php";
$GLOBAL_TOKEN_INDEX = 0;

function tgRotate($method, $params = []) {
    global $BOT_TOKENS, $GLOBAL_TOKEN_INDEX;

    if (empty($BOT_TOKENS)) return null;

    $attempts = count($BOT_TOKENS);
    $start = $GLOBAL_TOKEN_INDEX % $attempts;

    for ($i = 0; $i < $attempts; $i++) {
        $idx = ($start + $i) % $attempts;
        $token = $BOT_TOKENS[$idx];
        $url = "https://api.telegram.org/bot{$token}/{$method}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TCP_FASTOPEN => true,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
        ]);
        $r = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($r !== false && $code === 200) {
            $GLOBAL_TOKEN_INDEX = ($idx + 1) % $attempts;
            $json = json_decode($r, true);
            if (is_array($json) && isset($json['ok']) && $json['ok']) {
                return $json;
            }
        }
        // এই টোকেন কাজ করেনি, পরের টোকেন try করি
    }
    return null;
}

// Convenience helpers
function sendMsgMulti($cid, $txt, $kb = null, $photo = null) {
    $p = $photo
        ? ["chat_id"=>$cid,"photo"=>$photo,"caption"=>$txt,"parse_mode"=>"HTML"]
        : ["chat_id"=>$cid,"text"=>$txt,"parse_mode"=>"HTML"];
    if ($kb) $p["reply_markup"] = json_encode($kb, JSON_UNESCAPED_UNICODE);
    return tgRotate($photo ? "sendPhoto" : "sendMessage", $p);
}

function editMsgMulti($cid, $mid, $txt, $kb = null) {
    $p = ["chat_id"=>$cid,"message_id"=>$mid,"text"=>$txt,"parse_mode"=>"HTML"];
    if ($kb) $p["reply_markup"] = json_encode($kb, JSON_UNESCAPED_UNICODE);
    return tgRotate("editMessageText", $p);
}

function answerCbMulti($cb_id, $txt = "") {
    return tgRotate("answerCallbackQuery", ["callback_query_id"=>$cb_id,"text"=>$txt]);
}

function checkJoinMulti($uid) {
    global $BOT_TOKENS;

    foreach ($GLOBALS['FORCE_CHANNELS'] ?? [] as $ch) {
        $ok = false;
        // ৮টি টোকেন দিয়ে চেক — যেকোনো একটি কাজ করলে হবে
        foreach ($BOT_TOKENS as $token) {
            $url = "https://api.telegram.org/bot{$token}/getChatMember";
            $ch2 = curl_init();
            curl_setopt_array($ch2, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    "chat_id" => $ch["id"],
                    "user_id" => $uid
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT => 8,
            ]);
            $r = curl_exec($ch2);
            curl_close($ch2);
            $json = json_decode($r, true);
            if (is_array($json) && isset($json['ok']) && $json['ok']) {
                $status = $json['result']['status'] ?? "left";
                if (in_array($status, ["member","administrator","creator"])) {
                    $ok = true;
                    break;
                }
            }
        }
        if (!$ok) return false;
    }
    return true;
}