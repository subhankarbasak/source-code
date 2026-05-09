<?php
/* ================================================================
 *  AUTHOR : Subhankar Basak
 *  GITHUB : https://github.com/subhankarbasak
 *  Date : 09/05/2026
 *  Email : subhankarbasakmail@gmail.com
 *  Mobile : 7001399732
 *  MAINTENANCE PAGE  —  Single File Edition ( V1 )
 *  Works on any PHP shared hosting (cPanel, Plesk, etc.)
 *  No Composer, no dependencies, no Node.js required.
 *  Deployed: https://csscwb.in
 * ================================================================
 *  ★  CONFIGURATION — Edit this block only
 * ================================================================ */
 
    // Laravel Routes: Web.php
    //     Route::match(['get', 'post'], '/maintenance-mode', function () {
    //         return require resource_path('views/web-views/maintenance-mode.blade.php'); // or wherever your file is
    //     });
        
    // app/Http/Middleware/VerifyCsrfToken.php:
    //     protected $except = [
    //         '/maintenance',       // Add the exact URL path you are using
    //         '/maintenance.php',   // Add this too if you access the .php file directly
    //     ];

define('CFG', [

    /* ── Site ──────────────────────────────────────────────── */
    'site_name'      => 'YourCompany',
    'site_tagline'   => 'We are making things better',
    'support_email'  => 'support@yourdomain.com',
    'maintenance_hrs'=> 24,           // Countdown hours shown in UI

    /* ── JSON Storage ───────────────────────────────────────── */
    'json_file'      => __DIR__ . '/submissions.json',

    /* ── Email — "From" identity ────────────────────────────── */
    'from_name'      => 'YourCompany Support',
    'from_email'     => 'noreply@yourdomain.com',
    'admin_email'    => 'you@yourdomain.com',  // BCC / admin copy

    /* ── SMTP (leave host empty to use PHP mail() fallback) ─── */
    /*    Gmail:    host=smtp.gmail.com   port=587  user=you@gmail.com  pass=App-Password  */
    /*    SendGrid: host=smtp.sendgrid.net port=587 user=apikey         pass=SG.xxx        */
    /*    Outlook:  host=smtp-mail.outlook.com port=587                                    */
    'smtp_host'      => '',           // e.g. 'smtp.gmail.com'
    'smtp_port'      => 587,
    'smtp_user'      => '',           // your SMTP username / email
    'smtp_pass'      => '',           // your SMTP password / App Password
    'smtp_secure'    => 'tls',        // 'tls' (STARTTLS on 587) | 'ssl' (on 465) | ''

    /* ── Rate limiting ──────────────────────────────────────── */
    'rate_limit'     => 5,            // max submissions per IP per window
    'rate_window'    => 900,          // seconds (15 min)

]);

/* ================================================================
 *  BACKEND — runs ONLY on AJAX POST (before any HTML output)
 * ================================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {

    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    // ── Parse JSON body ──────────────────────────────────────
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid request body.']);
        exit;
    }

    // ── Rate limiting (file-based with locking) ──────────────
    // Fixed: Using REMOTE_ADDR to prevent IP spoofing bypass
    $ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rl_file  = sys_get_temp_dir() . '/maint_rl_' . md5($ip) . '.json';
    $rl_data  = ['count' => 0, 'window_start' => time()];
    $fp_rl    = false;

    if (is_writable(sys_get_temp_dir())) {
        $fp_rl = @fopen($rl_file, 'c+');
        if ($fp_rl) {
            flock($fp_rl, LOCK_EX);
            $contents = stream_get_contents($fp_rl);
            if ($contents) {
                $parsed_rl = json_decode($contents, true);
                if (is_array($parsed_rl)) $rl_data = $parsed_rl;
            }
            if ((time() - $rl_data['window_start']) > CFG['rate_window']) {
                $rl_data = ['count' => 0, 'window_start' => time()];
            }

            if ($rl_data['count'] >= CFG['rate_limit']) {
                flock($fp_rl, LOCK_UN);
                fclose($fp_rl);
                http_response_code(429);
                echo json_encode(['success' => false, 'message' => 'Too many submissions. Please try again later.']);
                exit;
            }
        }
    }

    // ── Sanitize helper ──────────────────────────────────────
    function clean(string $v, int $max = 255): string {
        return substr(strip_tags(trim($v)), 0, $max);
    }

    // ── Validate ─────────────────────────────────────────────
    $errors = [];

    $fullName = clean($body['fullName'] ?? '', 80);
    $email    = clean($body['email']    ?? '', 120);
    $mobile   = clean($body['mobile']   ?? '', 20);
    $company  = clean($body['company']  ?? '', 100);
    $message  = clean($body['message']  ?? '', 500);

    if (strlen($fullName) < 2 || !preg_match("/^[a-zA-Z\s'\-\.]+$/u", $fullName))
        $errors['fullName'] = 'Valid full name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors['email']    = 'Valid email address is required.';
    if (!preg_match('/^\+?[0-9\s\-\(\)]{7,20}$/', $mobile))
        $errors['mobile']   = 'Valid mobile number is required.';
    if (strlen($message) < 10)
        $errors['message']  = 'Message must be at least 10 characters.';

    if ($errors) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Validation failed.', 'errors' => $errors]);
        exit;
    }

    // ── Build submission record ──────────────────────────────
    $submission = [
        'id'        => 'sub_' . time() . '_' . substr(bin2hex(random_bytes(4)), 0, 6),
        'fullName'  => $fullName,
        'email'     => strtolower($email),
        'mobile'    => $mobile,
        'company'   => $company ?: null,
        'message'   => $message,
        'timestamp' => date('c'),
        'ip'        => $ip,
        'userAgent' => substr(clean($body['userAgent'] ?? '', 150), 0, 150), // Fixed: sanitized
    ];

    // ── Atomic save to JSON (Locking to prevent race conditions) ──
    $store = ['submissions' => [], 'lastUpdated' => null, 'totalCount' => 0];
    $json_file = CFG['json_file'];

    $fp = @fopen($json_file, 'c+');
    if (!$fp) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not open storage file. Check folder write permissions.']);
        exit;
    }

    flock($fp, LOCK_EX);
    $contents = stream_get_contents($fp);
    if ($contents) {
        $parsed = json_decode($contents, true);
        if (is_array($parsed)) $store = $parsed;
    }

    $store['submissions'][] = $submission;
    $store['lastUpdated']   = date('c');
    $store['totalCount']    = count($store['submissions']);

    $json_out = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $json_out);
    flock($fp, LOCK_UN);
    fclose($fp);

    // ── Update rate limit counter ────────────────────────────
    $rl_data['count']++;
    if ($fp_rl) {
        rewind($fp_rl);
        ftruncate($fp_rl, 0);
        fwrite($fp_rl, json_encode($rl_data));
        flock($fp_rl, LOCK_UN);
        fclose($fp_rl);
    }

    // ── Send email ───────────────────────────────────────────
    $emailSent = false;
    $emailError = '';

    // Fixed: Pre-escaping variables for safe HTML insertion
    $hFirstName = htmlspecialchars(explode(' ', $fullName)[0], ENT_QUOTES, 'UTF-8');
    $hFullName  = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
    $hEmail     = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $hMobile    = htmlspecialchars($mobile, ENT_QUOTES, 'UTF-8');
    $hMessage   = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

    $companyRow = $submission['company']
        ? '<tr><td style="color:#64748b;font-size:13px;padding-bottom:10px;">Company</td><td style="color:#f1f5f9;font-size:13px;font-weight:500;padding-bottom:10px;">' . htmlspecialchars($submission['company'], ENT_QUOTES, 'UTF-8') . '</td></tr>'
        : '';

    $yearNow      = date('Y');
    $siteName     = CFG['site_name'];
    $supportEmail = CFG['support_email'];
    $formattedDate = date('l, F j Y \a\t g:i A T');

    // Fixed: Removed the invalid $( ... ) JavaScript syntax and properly closed PHP tags
    $htmlEmail = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>You're on the list!</title></head>
<body style="margin:0;padding:0;background:#0d1428;font-family:'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#0d1428;padding:40px 16px;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">
  <tr>
    <td style="background:linear-gradient(135deg,#6366f1,#8b5cf6);border-radius:16px 16px 0 0;padding:36px 40px;text-align:center;">
      <div style="display:inline-block;width:56px;height:56px;border-radius:14px;background:rgba(255,255,255,0.15);line-height:56px;text-align:center;margin-bottom:16px;font-size:26px;">&#9889;</div>
      <h1 style="margin:0;color:#fff;font-size:26px;font-weight:700;letter-spacing:-0.5px;">You're on the list!</h1>
      <p style="margin:8px 0 0;color:rgba(255,255,255,0.75);font-size:14px;line-height:1.6;">We'll notify you the moment we're back online.</p>
    </td>
  </tr>
  <tr>
    <td style="background:#111827;padding:36px 40px;border-left:1px solid #1f2937;border-right:1px solid #1f2937;">
      <p style="margin:0 0 18px;color:#94a3b8;font-size:15px;line-height:1.7;">Hi <strong style="color:#f1f5f9;">{$hFirstName}</strong>,</p>
      <p style="margin:0 0 24px;color:#94a3b8;font-size:15px;line-height:1.7;">Thank you for reaching out during our scheduled maintenance. We've received your submission and our team is working hard to get everything back online as quickly as possible.</p>
      <div style="background:#1e293b;border:1px solid #334155;border-radius:12px;padding:24px;margin-bottom:28px;">
        <p style="margin:0 0 14px;color:#6366f1;font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;">Your Submission Details</p>
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr><td style="color:#64748b;font-size:13px;padding-bottom:10px;width:110px;">Name</td><td style="color:#f1f5f9;font-size:13px;font-weight:500;padding-bottom:10px;">{$hFullName}</td></tr>
          <tr><td style="color:#64748b;font-size:13px;padding-bottom:10px;">Email</td><td style="color:#f1f5f9;font-size:13px;font-weight:500;padding-bottom:10px;">{$hEmail}</td></tr>
          <tr><td style="color:#64748b;font-size:13px;padding-bottom:10px;">Mobile</td><td style="color:#f1f5f9;font-size:13px;font-weight:500;padding-bottom:10px;">{$hMobile}</td></tr>
          {$companyRow}
          <tr><td style="color:#64748b;font-size:13px;padding-bottom:10px;">Submitted</td><td style="color:#f1f5f9;font-size:13px;font-weight:500;padding-bottom:10px;">{$formattedDate}</td></tr>
          <tr><td style="color:#64748b;font-size:13px;vertical-align:top;">Message</td><td style="color:#94a3b8;font-size:13px;font-style:italic;">&ldquo;{$hMessage}&rdquo;</td></tr>
        </table>
      </div>
      <div style="background:linear-gradient(135deg,rgba(99,102,241,0.15),rgba(139,92,246,0.1));border:1px solid rgba(99,102,241,0.25);border-radius:12px;padding:20px;text-align:center;">
        <p style="margin:0 0 12px;color:#94a3b8;font-size:13px;">Need urgent assistance? Our team is available 24/7.</p>
        <a href="mailto:{$supportEmail}" style="display:inline-block;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-size:13px;font-weight:600;">Contact Support &rarr;</a>
      </div>
    </td>
  </tr>
  <tr>
    <td style="background:#0d1428;border:1px solid #1f2937;border-top:none;border-radius:0 0 16px 16px;padding:24px 40px;text-align:center;">
      <p style="margin:0 0 6px;color:#475569;font-size:12px;">&copy; {$yearNow} {$siteName}. All rights reserved.</p>
      <p style="margin:0;color:#334155;font-size:11px;">You received this because you submitted a form on our maintenance page.</p>
    </td>
  </tr>
</table>
</td></tr>
</table>
</body></html>
HTML;

    $plainText = "Hi {$hFirstName},\n\nThank you for contacting us during maintenance.\n\nWe've received your submission and will notify you at {$hEmail} as soon as we're back online.\n\nSubmission ID: {$submission['id']}\nSubmitted: {$formattedDate}\n\nFor urgent help: {$supportEmail}\n\n© {$yearNow} {$siteName}";

    $subject   = "You're on the list, {$hFirstName}! — {$siteName} Maintenance";
    $fromName  = CFG['from_name'];
    $fromEmail = CFG['from_email'];

    // ── SMTP send function (pure PHP, no dependencies) ───────
    function smtpSend(string $host, int $port, string $user, string $pass, string $secure,
                      string $from, string $fromName, string $to, string $subject,
                      string $html, string $plain): array {

        $timeout = 15;
        $boundary = '=_' . md5(uniqid('', true));

        // Build MIME message
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $headers .= "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>\r\n";
        $headers .= "Reply-To: {$from}\r\n";
        $headers .= "X-Mailer: MaintenancePage/1.0\r\n";

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($plain)) . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($html)) . "\r\n";
        $body .= "--{$boundary}--";

        // Open socket
        // Fixed: SSL Verification enabled for security
        $ctx = stream_context_create(['ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
        ]]);

        $dsn    = ($secure === 'ssl') ? "ssl://{$host}" : "tcp://{$host}";
        $socket = @stream_socket_client("{$dsn}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);

        if (!$socket) return [false, "Connection failed: {$errstr} ({$errno})"];

        stream_set_timeout($socket, $timeout);

        $read = function() use ($socket) {
            $data = '';
            while ($line = fgets($socket, 512)) {
                $data .= $line;
                if (substr($line, 3, 1) === ' ') break;
            }
            return $data;
        };

        $cmd = function(string $c) use ($socket, $read) {
            fwrite($socket, $c . "\r\n");
            return $read();
        };

        $read(); // greeting

        if ($secure === 'tls') {
            $cmd("EHLO localhost");
            $cmd("STARTTLS");
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                return [false, 'STARTTLS failed'];
            }
        }

        $cmd("EHLO localhost");
        $cmd("AUTH LOGIN");
        $cmd(base64_encode($user));
        $r = $cmd(base64_encode($pass));
        if (strpos($r, '235') === false) { fclose($socket); return [false, "AUTH failed: {$r}"]; }

        $cmd("MAIL FROM:<{$from}>");
        $cmd("RCPT TO:<{$to}>");
        $cmd("DATA");
        $msg  = "To: {$to}\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $msg .= $headers . "\r\n" . $body . "\r\n.";
        $r = $cmd($msg);
        $cmd("QUIT");
        fclose($socket);

        $ok = strpos($r, '250') !== false;
        return [$ok, $ok ? '' : "Send failed: {$r}"];
    }

    // ── Attempt SMTP or fall back to mail() ──────────────────
    if (!empty(CFG['smtp_host']) && !empty(CFG['smtp_user']) && !empty(CFG['smtp_pass'])) {
        [$emailSent, $emailError] = smtpSend(
            CFG['smtp_host'], CFG['smtp_port'],
            CFG['smtp_user'], CFG['smtp_pass'], CFG['smtp_secure'],
            $fromEmail, $fromName,
            $submission['email'], $subject, $htmlEmail, $plainText
        );

        // Admin copy
        if ($emailSent && !empty(CFG['admin_email'])) {
            smtpSend(
                CFG['smtp_host'], CFG['smtp_port'],
                CFG['smtp_user'], CFG['smtp_pass'], CFG['smtp_secure'],
                $fromEmail, $fromName,
                CFG['admin_email'],
                '[Admin] New submission from ' . $submission['fullName'],
                '<pre style="font-family:monospace;background:#111;color:#0f0;padding:16px;">' . htmlspecialchars(json_encode($submission, JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8') . '</pre>',
                json_encode($submission, JSON_PRETTY_PRINT)
            );
        }
    } else {
        // PHP mail() fallback (works on most cPanel hosts)
        $boundary = '=_' . md5(uniqid('', true));
        $mHeaders  = "MIME-Version: 1.0\r\n";
        $mHeaders .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $mHeaders .= "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>\r\n";
        $mHeaders .= "Reply-To: {$fromEmail}\r\n";
        $mHeaders .= "X-Mailer: PHP/" . PHP_VERSION;

        $mBody  = "--{$boundary}\r\n";
        $mBody .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $mBody .= chunk_split(base64_encode($plainText)) . "\r\n";
        $mBody .= "--{$boundary}\r\n";
        $mBody .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $mBody .= chunk_split(base64_encode($htmlEmail)) . "\r\n";
        $mBody .= "--{$boundary}--";

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $emailSent = mail($submission['email'], $encodedSubject, $mBody, $mHeaders);

        if ($emailSent && !empty(CFG['admin_email'])) {
            mail(CFG['admin_email'], '[Admin] New submission — ' . CFG['site_name'],
                json_encode($submission, JSON_PRETTY_PRINT),
                "From: {$fromEmail}\r\nContent-Type: text/plain; charset=UTF-8");
        }
    }

    echo json_encode([
        'success'    => true,
        'message'    => $emailSent
            ? 'Submission saved and confirmation email sent!'
            : 'Submission saved! (Email: ' . ($emailError ?: 'unavailable') . ')',
        'id'         => $submission['id'],
        'emailSent'  => $emailSent,
    ]);
    exit;
}

// ── PHP config for the frontend ───────────────────────────────
 $siteName = CFG['site_name'];
 $countdownHours = (int) CFG['maintenance_hrs'];
 $supportEmail   = CFG['support_email'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta http-equiv="X-UA-Compatible" content="IE=edge"/>
  <meta name="robots" content="noindex, nofollow"/>
  <meta name="description" content="We're performing scheduled maintenance. Back soon!"/>
  <meta name="csrf-token" content="<?php echo csrf_token(); ?>">
  <title>Under Maintenance — <?= htmlspecialchars($siteName) ?></title>

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,300&display=swap" rel="stylesheet"/>

  <!-- Tailwind CDN -->
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- jQuery + Toastr -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css"/>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>

  <script>
    tailwind.config = {
      darkMode: ['class', '[data-theme="light"]'],
      theme: { extend: {
        fontFamily: { display: ['Syne','sans-serif'], body: ['DM Sans','sans-serif'] }
      }}
    };
  </script>

  <style>
    /* ── Variables ──────────────────────────────────────────── */
    :root {
      --bg:           #f0f4ff;
      --glass-bg:     rgba(255,255,255,0.55);
      --glass-border: rgba(255,255,255,0.78);
      --glass-shadow: 0 8px 40px rgba(99,102,241,0.1);
      --text:         #0f172a;
      --text-2:       #475569;
      --text-3:       #94a3b8;
      --accent:       #6366f1;
      --accent-h:     #4f46e5;
      --accent-glow:  rgba(99,102,241,0.32);
      --success:      #10b981;
      --error:        #ef4444;
      --inp-bg:       rgba(255,255,255,0.72);
      --inp-border:   rgba(99,102,241,0.22);
      --inp-focus:    rgba(99,102,241,0.55);
      --orb1: rgba(99,102,241,0.15);
      --orb2: rgba(139,92,246,0.12);
      --orb3: rgba(59,130,246,0.10);
    }
    [data-theme="dark"] {
      --bg:           #060b1a;
      --glass-bg:     rgba(255,255,255,0.04);
      --glass-border: rgba(255,255,255,0.09);
      --glass-shadow: 0 8px 40px rgba(0,0,0,0.5);
      --text:         #f1f5f9;
      --text-2:       #94a3b8;
      --text-3:       #475569;
      --accent:       #818cf8;
      --accent-h:     #6366f1;
      --accent-glow:  rgba(129,140,248,0.38);
      --success:      #34d399;
      --error:        #f87171;
      --inp-bg:       rgba(255,255,255,0.05);
      --inp-border:   rgba(129,140,248,0.18);
      --inp-focus:    rgba(129,140,248,0.48);
      --orb1: rgba(99,102,241,0.2);
      --orb2: rgba(139,92,246,0.15);
      --orb3: rgba(59,130,246,0.10);
    }

    *,*::before,*::after { box-sizing:border-box; }
    html { scroll-behavior:smooth; }

    body {
      font-family:'DM Sans',sans-serif;
      background:var(--bg);
      color:var(--text);
      min-height:100vh;
      overflow-x:hidden;
      transition:background .35s,color .35s;
    }

    /* ── Grid texture ───────────────────────────────────────── */
    .grid-bg {
      position:fixed;inset:0;z-index:0;pointer-events:none;
      background-image:
        linear-gradient(rgba(99,102,241,.045) 1px,transparent 1px),
        linear-gradient(90deg,rgba(99,102,241,.045) 1px,transparent 1px);
      background-size:52px 52px;
    }
    [data-theme="light"] .grid-bg {
      background-image:
        linear-gradient(rgba(99,102,241,.075) 1px,transparent 1px),
        linear-gradient(90deg,rgba(99,102,241,.075) 1px,transparent 1px);
    }

    /* ── Animated orbs ──────────────────────────────────────── */
    .orb { position:fixed;border-radius:50%;filter:blur(90px);pointer-events:none;z-index:0;animation:orbF 14s ease-in-out infinite; }
    .orb-1 { width:600px;height:600px;background:var(--orb1);top:-180px;left:-160px;animation-delay:0s; }
    .orb-2 { width:500px;height:500px;background:var(--orb2);bottom:-120px;right:-120px;animation-delay:-5s; }
    .orb-3 { width:380px;height:380px;background:var(--orb3);top:42%;left:50%;transform:translate(-50%,-50%);animation-delay:-9s; }
    @keyframes orbF {
      0%,100% { transform:translateY(0) scale(1); }
      33%      { transform:translateY(-28px) scale(1.04); }
      66%      { transform:translateY(18px) scale(0.97); }
    }
    .orb-3 { animation-name:orbF3; }
    @keyframes orbF3 {
      0%,100% { transform:translate(-50%,-50%) scale(1); }
      33%      { transform:translate(-50%,-50%) scale(1.06); }
      66%      { transform:translate(-50%,-50%) scale(0.95); }
    }

    /* ── Noise overlay ──────────────────────────────────────── */
    body::before {
      content:'';position:fixed;inset:0;pointer-events:none;z-index:1;opacity:.35;
      background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.88' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
    }

    /* ── Glass card ─────────────────────────────────────────── */
    .glass {
      background:var(--glass-bg);
      border:1px solid var(--glass-border);
      box-shadow:var(--glass-shadow);
      backdrop-filter:blur(22px) saturate(180%);
      -webkit-backdrop-filter:blur(22px) saturate(180%);
      transition:background .35s,border-color .35s,box-shadow .35s;
    }

    /* ── Countdown blocks ───────────────────────────────────── */
    .cd-block {
      background:var(--glass-bg);
      border:1px solid var(--glass-border);
      backdrop-filter:blur(14px);
      -webkit-backdrop-filter:blur(14px);
      border-radius:1rem;
      padding:1rem .75rem;
      text-align:center;
    }
    .cd-num {
      font-family:'Syne',sans-serif;
      font-size:clamp(1.5rem,4vw,2rem);
      font-weight:800;
      color:var(--text);
      line-height:1;
    }
    .cd-label {
      font-size:.62rem;font-weight:600;color:var(--text-3);
      letter-spacing:.08em;text-transform:uppercase;margin-top:4px;
    }

    /* ── Progress shimmer ───────────────────────────────────── */
    .prog-bar {
      height:5px;border-radius:999px;
      background:linear-gradient(90deg,var(--accent),#8b5cf6,#3b82f6);
      background-size:200% 100%;
      animation:shimmer 2.5s linear infinite;
    }
    @keyframes shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }

    /* ── Status pulse ───────────────────────────────────────── */
    .dot-pulse {
      width:9px;height:9px;border-radius:50%;background:#f59e0b;
      animation:pulse 2s ease infinite;
      box-shadow:0 0 0 0 rgba(245,158,11,.6);
    }
    @keyframes pulse {
      0%  { box-shadow:0 0 0 0 rgba(245,158,11,.6); }
      70% { box-shadow:0 0 0 9px rgba(245,158,11,0); }
      100%{ box-shadow:0 0 0 0 rgba(245,158,11,0); }
    }

    /* ── Form inputs ────────────────────────────────────────── */
    .fi {
      width:100%;padding:13px 17px;
      background:var(--inp-bg);
      border:1.5px solid var(--inp-border);
      border-radius:11px;
      color:var(--text);
      font-family:'DM Sans',sans-serif;
      font-size:.9rem;outline:none;
      backdrop-filter:blur(8px);
      transition:border-color .22s,box-shadow .22s,background .22s;
    }
    .fi::placeholder { color:var(--text-3); }
    .fi:focus { border-color:var(--inp-focus);box-shadow:0 0 0 3px var(--accent-glow); }
    .fi.err { border-color:var(--error)!important;box-shadow:0 0 0 3px rgba(239,68,68,.18)!important; }
    .fi.ok  { border-color:var(--success)!important;box-shadow:0 0 0 3px rgba(16,185,129,.18)!important; }

    /* ── Label ──────────────────────────────────────────────── */
    .lbl {
      display:block;
      font-family:'Syne',sans-serif;font-size:.78rem;font-weight:700;
      letter-spacing:.05em;text-transform:uppercase;color:var(--text-2);
      margin-bottom:6px;
    }

    /* ── Error text ─────────────────────────────────────────── */
    .fe { display:none;align-items:center;gap:4px;color:var(--error);font-size:.76rem;margin-top:4px; }
    .fe.on { display:flex; }

    /* ── Char counter ───────────────────────────────────────── */
    .cc { font-size:.72rem;color:var(--text-3);text-align:right;margin-top:3px;transition:color .2s; }
    .cc.warn { color:#f59e0b; }
    .cc.over { color:var(--error); }

    /* ── Submit button ──────────────────────────────────────── */
    .btn-sub {
      width:100%;padding:14px 28px;
      background:linear-gradient(135deg,var(--accent),#8b5cf6);
      color:#fff;border:none;border-radius:11px;cursor:pointer;
      font-family:'Syne',sans-serif;font-weight:700;font-size:.92rem;
      letter-spacing:.025em;
      transition:transform .2s,box-shadow .2s,opacity .2s;
      position:relative;overflow:hidden;
    }
    .btn-sub::before {
      content:'';position:absolute;inset:0;
      background:linear-gradient(135deg,rgba(255,255,255,.15),transparent);
      opacity:0;transition:opacity .2s;
    }
    .btn-sub:hover:not(:disabled) { transform:translateY(-2px);box-shadow:0 10px 32px var(--accent-glow); }
    .btn-sub:hover:not(:disabled)::before { opacity:1; }
    .btn-sub:active:not(:disabled) { transform:translateY(0); }
    .btn-sub:disabled { opacity:.6;cursor:not-allowed; }

    /* ── Theme toggle ───────────────────────────────────────── */
    .tt {
      width:50px;height:27px;border-radius:999px;cursor:pointer;
      background:var(--glass-bg);border:1.5px solid var(--glass-border);
      backdrop-filter:blur(10px);position:relative;outline:none;
      transition:background .3s;flex-shrink:0;
    }
    .tt .knob {
      position:absolute;top:3px;left:3px;
      width:18px;height:18px;border-radius:50%;
      background:var(--accent);
      transition:transform .3s cubic-bezier(.34,1.56,.64,1),background .3s;
    }
    [data-theme="dark"] .tt .knob { transform:translateX(23px); }

    /* ── Social icons ───────────────────────────────────────── */
    .si {
      display:inline-flex;align-items:center;justify-content:center;
      width:38px;height:38px;border-radius:10px;
      background:var(--glass-bg);border:1px solid var(--glass-border);
      color:var(--text-2);text-decoration:none;
      transition:transform .2s,box-shadow .2s,color .2s,border-color .2s;
      backdrop-filter:blur(8px);
    }
    .si:hover { transform:translateY(-3px);color:var(--accent);border-color:var(--accent-glow);box-shadow:0 4px 16px var(--accent-glow); }

    /* ── Entry animations ───────────────────────────────────── */
    .fu { opacity:0;transform:translateY(26px);animation:fadeUp .7s cubic-bezier(.22,1,.36,1) forwards; }
    @keyframes fadeUp { to{opacity:1;transform:translateY(0)} }
    .d1{animation-delay:.08s}.d2{animation-delay:.18s}.d3{animation-delay:.28s}
    .d4{animation-delay:.38s}.d5{animation-delay:.5s}.d6{animation-delay:.65s}

    /* ── Spinner ────────────────────────────────────────────── */
    .spin {
      display:inline-block;width:17px;height:17px;
      border:2px solid rgba(255,255,255,.3);border-top-color:#fff;
      border-radius:50%;animation:sp .65s linear infinite;
    }
    @keyframes sp { to{transform:rotate(360deg)} }

    /* ── Success overlay ────────────────────────────────────── */
    .sov { display:none;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:1.5rem; }
    .sov.on { display:flex;animation:fadeUp .5s cubic-bezier(.22,1,.36,1) forwards; }
    .ck-circle {
      width:70px;height:70px;border-radius:50%;margin-bottom:1.25rem;
      background:linear-gradient(135deg,var(--success),#059669);
      display:flex;align-items:center;justify-content:center;
      animation:ckPop .8s cubic-bezier(.22,1,.36,1);
    }
    @keyframes ckPop {
      0%  {transform:scale(.7);box-shadow:0 0 0 0 rgba(16,185,129,.6)}
      60% {transform:scale(1.08);box-shadow:0 0 0 18px rgba(16,185,129,0)}
      100%{transform:scale(1);box-shadow:0 0 0 0 rgba(16,185,129,0)}
    }

    /* ── Toastr tweaks ──────────────────────────────────────── */
    #toast-container>.toast {
      border-radius:12px!important;backdrop-filter:blur(16px)!important;
      font-family:'DM Sans',sans-serif!important;font-size:.86rem!important;
      padding:13px 16px 13px 54px!important;box-shadow:0 8px 30px rgba(0,0,0,.22)!important;
    }
    #toast-container>.toast-success { background:rgba(16,185,129,.88)!important; }
    #toast-container>.toast-error   { background:rgba(239,68,68,.88)!important; }
    #toast-container>.toast-info    { background:rgba(99,102,241,.88)!important; }
    #toast-container>.toast-warning { background:rgba(245,158,11,.88)!important; }

    /* ── Scrollbar ──────────────────────────────────────────── */
    ::-webkit-scrollbar{width:5px}
    ::-webkit-scrollbar-track{background:transparent}
    ::-webkit-scrollbar-thumb{background:var(--accent);border-radius:999px}
  </style>
</head>
<body>

<!-- Layers -->
<div class="grid-bg" aria-hidden="true"></div>
<div class="orb orb-1" aria-hidden="true"></div>
<div class="orb orb-2" aria-hidden="true"></div>
<div class="orb orb-3" aria-hidden="true"></div>

<div class="relative z-10 min-h-screen flex flex-col">

  <!-- ── Header ──────────────────────────────────────────── -->
  <header class="glass mx-3 mt-3 sm:mx-6 sm:mt-5 rounded-2xl">
    <div class="max-w-7xl mx-auto px-5 py-4 flex items-center justify-between">

      <!-- Logo -->
      <div class="flex items-center gap-2.5 fu d1">
        <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0"
             style="background:linear-gradient(135deg,var(--accent),#8b5cf6);">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
          </svg>
        </div>
        <span style="font-family:'Syne',sans-serif;font-weight:800;font-size:1.1rem;color:var(--text);">
          <span style="color:var(--accent);"><?= htmlspecialchars($siteName) ?></span>
        </span>
      </div>

      <!-- Controls -->
      <div class="flex items-center gap-3 fu d1">
        <div class="hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-semibold"
             style="background:rgba(245,158,11,.1);color:#f59e0b;border:1px solid rgba(245,158,11,.22);font-family:'DM Sans',sans-serif;">
          <span class="dot-pulse"></span> Maintenance Mode
        </div>
        <div class="flex items-center gap-1.5">
          <!-- Sun -->
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--text-3);"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
          <button class="tt" id="themeToggle" aria-label="Toggle theme"><div class="knob"></div></button>
          <!-- Moon -->
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--text-3);"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
        </div>
      </div>

    </div>
  </header>

  <!-- ── Main ────────────────────────────────────────────── -->
  <main class="flex-1 flex items-center justify-center px-3 py-10 sm:py-14">
    <div class="w-full max-w-6xl mx-auto">
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 xl:gap-14 items-center">

        <!-- ── LEFT: Info ─────────────────────────────── -->
        <div class="space-y-7">

          <!-- Badge -->
          <div class="fu d1 inline-flex items-center gap-2 px-4 py-2 rounded-full text-sm font-semibold"
               style="background:rgba(99,102,241,.1);color:var(--accent);border:1px solid rgba(99,102,241,.2);font-family:'DM Sans',sans-serif;">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            Scheduled Maintenance
          </div>

          <!-- Headline -->
          <div class="fu d2">
            <h1 style="font-family:'Syne',sans-serif;font-weight:800;line-height:1.06;color:var(--text);"
                class="text-4xl sm:text-5xl xl:text-[3.4rem]">
              <?= htmlspecialchars(CFG['site_tagline']) ?><br/>
              <span style="background:linear-gradient(130deg,var(--accent),#a78bfa,#60a5fa);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;">
                for you.
              </span>
            </h1>
            <p style="color:var(--text-2);font-size:1rem;line-height:1.75;margin-top:1.1rem;">
              Our engineers are performing scheduled upgrades to deliver a faster, more secure experience. We appreciate your patience and will be back shortly.
            </p>
          </div>

          <!-- Progress -->
          <div class="fu d3">
            <div class="flex justify-between items-center mb-2">
              <span style="font-family:'Syne',sans-serif;font-size:.75rem;font-weight:700;color:var(--text-3);letter-spacing:.07em;text-transform:uppercase;">Upgrade Progress</span>
              <span style="font-family:'Syne',sans-serif;font-size:.88rem;font-weight:800;color:var(--accent);">72%</span>
            </div>
            <div style="height:6px;border-radius:999px;background:var(--inp-bg);border:1px solid var(--glass-border);overflow:hidden;">
              <div class="prog-bar" style="width:72%;"></div>
            </div>
          </div>

          <!-- Countdown -->
          <div class="fu d3">
            <p style="font-family:'Syne',sans-serif;font-size:.74rem;font-weight:700;color:var(--text-3);letter-spacing:.07em;text-transform:uppercase;margin-bottom:.7rem;">
              Estimated Time Remaining
            </p>
            <div class="grid grid-cols-4 gap-2.5">
              <div class="cd-block"><div class="cd-num" id="cdH">00</div><div class="cd-label">Hours</div></div>
              <div class="cd-block"><div class="cd-num" id="cdM">00</div><div class="cd-label">Mins</div></div>
              <div class="cd-block"><div class="cd-num" id="cdS">00</div><div class="cd-label">Secs</div></div>
              <div class="cd-block" style="border-color:var(--accent-glow);">
                <div class="cd-num" style="color:var(--accent);"><?= $countdownHours ?><span style="font-size:.9rem;">h</span></div>
                <div class="cd-label">Total</div>
              </div>
            </div>
          </div>

          <!-- Task list -->
          <div class="fu d4 space-y-2.5">
            <p style="font-family:'Syne',sans-serif;font-size:.74rem;font-weight:700;color:var(--text-3);letter-spacing:.07em;text-transform:uppercase;margin-bottom:.6rem;">What We're Working On</p>
            <div class="flex items-center gap-3" style="color:var(--text-2);font-size:.88rem;">
              <div style="width:7px;height:7px;border-radius:50%;background:var(--success);flex-shrink:0;"></div>
              Database optimisation &amp; indexing
            </div>
            <div class="flex items-center gap-3" style="color:var(--text-2);font-size:.88rem;">
              <div style="width:7px;height:7px;border-radius:50%;background:var(--success);flex-shrink:0;"></div>
              Security patches &amp; SSL renewal
            </div>
            <div class="flex items-center gap-3" style="color:var(--text-2);font-size:.88rem;">
              <div class="spin" style="width:10px;height:10px;border-width:1.5px;flex-shrink:0;"></div>
              Infrastructure scaling upgrade
            </div>
            <div class="flex items-center gap-3" style="color:var(--text-3);font-size:.88rem;">
              <div style="width:7px;height:7px;border-radius:50%;border:1.5px solid var(--text-3);flex-shrink:0;"></div>
              CDN cache refresh &amp; deployment
            </div>
          </div>

          <!-- Socials -->
          <div class="fu d5 flex items-center gap-3">
            <span style="font-size:.76rem;color:var(--text-3);font-weight:500;">Follow updates:</span>
            <a href="#" class="si" aria-label="X / Twitter">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.73-8.835L2.25 2.25h6.883l4.26 5.632L18.244 2.25zm-1.161 17.52h1.833L7.084 4.126H5.117L17.083 19.77z"/></svg>
            </a>
            <a href="#" class="si" aria-label="LinkedIn">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/></svg>
            </a>
            <a href="#" class="si" aria-label="GitHub">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"/></svg>
            </a>
          </div>

        </div><!-- /Left -->

        <!-- ── RIGHT: Form ────────────────────────────── -->
        <div class="fu d4">
          <div class="glass rounded-3xl p-6 sm:p-8 relative overflow-hidden">

            <!-- Decorative glow -->
            <div style="position:absolute;top:-50px;right:-50px;width:200px;height:200px;border-radius:50%;background:linear-gradient(135deg,var(--accent),#8b5cf6);opacity:.07;pointer-events:none;"></div>

            <!-- Form heading -->
            <div class="mb-6">
              <h2 style="font-family:'Syne',sans-serif;font-weight:700;font-size:1.4rem;color:var(--text);margin-bottom:.35rem;">
                Stay in the loop
              </h2>
              <p style="color:var(--text-2);font-size:.86rem;line-height:1.65;">
                Leave your details — we'll notify you the moment we're live and send a personalised confirmation to your inbox.
              </p>
            </div>

            <!-- ──────────── FORM ──────────────────────── -->
            <form id="mForm" novalidate autocomplete="off">

              <!-- Row 1: Name + Mobile -->
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5 mb-3.5">
                <div>
                  <label class="lbl" for="fullName">Full Name <span style="color:var(--error);">*</span></label>
                  <input class="fi" type="text" id="fullName" placeholder="Your Full Name" maxlength="80" spellcheck="false"/>
                  <div class="fe" id="e-fullName"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><span></span></div>
                </div>
                <div>
                  <label class="lbl" for="mobile">Mobile <span style="color:var(--error);">*</span></label>
                  <input class="fi" type="tel" id="mobile" placeholder="+91 9876543210" maxlength="20" inputmode="tel"/>
                  <div class="fe" id="e-mobile"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><span></span></div>
                </div>
              </div>

              <!-- Email -->
              <div class="mb-3.5">
                <label class="lbl" for="email">Email Address <span style="color:var(--error);">*</span></label>
                <input class="fi" type="email" id="email" placeholder="support@yourdomain.com" maxlength="120" spellcheck="false"/>
                <div class="fe" id="e-email"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><span></span></div>
              </div>

              <!-- Company (optional) -->
              <div class="mb-3.5">
                <label class="lbl" for="company">
                  Subjet
                  <span style="font-weight:400;text-transform:none;font-size:.73rem;letter-spacing:0;color:var(--text-3);">(optional)</span>
                </label>
                <input class="fi" type="text" id="company" placeholder="Enter Your Subject" maxlength="100"/>
              </div>

              <!-- Message -->
              <div class="mb-4">
                <label class="lbl" for="message">Message <span style="color:var(--error);">*</span></label>
                <textarea class="fi" id="message" placeholder="Tell us about your query, or simply say hello…" rows="4" maxlength="500" style="resize:vertical;min-height:95px;"></textarea>
                <div class="cc" id="charCount">0 / 500</div>
                <div class="fe" id="e-message"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><span></span></div>
              </div>

              <!-- Consent -->
              <div class="mb-1 flex items-start gap-2.5">
                <input type="checkbox" id="consent" style="width:15px;height:15px;margin-top:2px;accent-color:var(--accent);flex-shrink:0;cursor:pointer;"/>
                <label for="consent" style="font-size:.78rem;color:var(--text-3);line-height:1.55;cursor:pointer;">
                  I agree to receive maintenance updates and a confirmation email. Data handled per our
                  <a href="#" style="color:var(--accent);text-decoration:underline;">Privacy Policy</a>.
                </label>
              </div>
              <div class="fe mb-3" id="e-consent"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><span></span></div>

              <!-- Submit -->
              <button type="submit" class="btn-sub" id="subBtn">
                <span id="btnTxt" class="flex items-center justify-center gap-2">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="M22 2L11 13"/><path d="M22 2L15 22 11 13 2 9l20-7z"/></svg>
                  Notify Me When You're Back
                </span>
              </button>

              <!-- Trust badges -->
              <div class="mt-3.5 flex items-center justify-center gap-5 flex-wrap">
                <span style="font-size:.7rem;color:var(--text-3);display:flex;align-items:center;gap:3px;">
                  <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                  256-bit SSL
                </span>
                <span style="font-size:.7rem;color:var(--text-3);display:flex;align-items:center;gap:3px;">
                  <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                  No spam, ever
                </span>
                <span style="font-size:.7rem;color:var(--text-3);display:flex;align-items:center;gap:3px;">
                  <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="20 6 9 17 4 12"/></svg>
                  GDPR compliant
                </span>
              </div>

            </form>

            <!-- ──────────── SUCCESS OVERLAY ──────────── -->
            <div class="sov" id="sov">
              <div class="ck-circle">
                <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <polyline points="20 6 9 17 4 12"/>
                </svg>
              </div>
              <h3 style="font-family:'Syne',sans-serif;font-weight:700;font-size:1.35rem;color:var(--text);margin-bottom:.4rem;">
                You're on the list!
              </h3>
              <p style="color:var(--text-2);font-size:.86rem;line-height:1.65;max-width:280px;">
                Your details are saved and a confirmation email is on its way. We'll ping you the moment we go live.
              </p>
              <button onclick="resetForm()" class="btn-sub mt-5" style="width:auto;padding:10px 26px;font-size:.82rem;">
                Submit Another
              </button>
            </div>

          </div>
        </div><!-- /Right -->

      </div><!-- /grid -->
    </div>
  </main>

  <!-- ── Footer ──────────────────────────────────────────── -->
  <footer class="glass mx-3 mb-3 sm:mx-6 sm:mb-5 rounded-2xl fu d6">
    <div class="max-w-7xl mx-auto px-5 py-4 flex flex-col sm:flex-row items-center justify-between gap-2">
      <p style="font-size:.75rem;color:var(--text-3);">
        &copy; <?= date('Y') ?> <?= htmlspecialchars($siteName) ?>. Need urgent help?
        <a href="mailto:<?= htmlspecialchars($supportEmail) ?>" style="color:var(--accent);"><?= htmlspecialchars($supportEmail) ?></a>
      </p>
      <div style="font-size:.73rem;color:var(--text-3);display:flex;align-items:center;gap:6px;">
        <span class="dot-pulse" style="width:7px;height:7px;"></span>
        All systems operational except web portal
      </div>
    </div>
  </footer>

</div><!-- /layout -->

<!-- ── Scripts ─────────────────────────────────────────────── -->
<script>
/* ── Toastr config ─────────────────────────────────────────── */
toastr.options = {
  closeButton:true, progressBar:true, newestOnTop:true,
  positionClass:'toast-top-right', timeOut:5500, extendedTimeOut:1500,
  showMethod:'slideDown', hideMethod:'slideUp'
};

/* ── Theme ─────────────────────────────────────────────────── */
const html = document.documentElement;
html.setAttribute('data-theme', localStorage.getItem('theme') || 'dark');

document.getElementById('themeToggle').addEventListener('click', () => {
  const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  html.setAttribute('data-theme', next);
  localStorage.setItem('theme', next);
});

document.getElementById('themeToggle').addEventListener('keydown', e => {
  if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); e.currentTarget.click(); }
});

/* ── Countdown ─────────────────────────────────────────────── */
const TOTAL_MS = <?= $countdownHours ?> * 3600000;
const t0 = Date.now();
function tick() {
  const rem = Math.max(0, TOTAL_MS - (Date.now() - t0));
  document.getElementById('cdH').textContent = String(Math.floor(rem / 3600000)).padStart(2,'0');
  document.getElementById('cdM').textContent = String(Math.floor(rem % 3600000 / 60000)).padStart(2,'0');
  document.getElementById('cdS').textContent = String(Math.floor(rem % 60000 / 1000)).padStart(2,'0');
}
tick(); setInterval(tick, 1000);

/* ── Character counter ─────────────────────────────────────── */
const msgEl = document.getElementById('message');
const ccEl  = document.getElementById('charCount');
msgEl.addEventListener('input', () => {
  const l = msgEl.value.length;
  ccEl.textContent = l + ' / 500';
  ccEl.className = 'cc' + (l > 450 ? ' over' : l > 370 ? ' warn' : '');
});

/* ── Validators ────────────────────────────────────────────── */
const V = {
  fullName: v => {
    v = v.trim();
    if (!v)           return 'Full name is required.';
    if (v.length < 2) return 'Name must be at least 2 characters.';
    if (!/^[a-zA-Z\s'\-\.]+$/.test(v)) return 'Name contains invalid characters.';
    return null;
  },
  email: v => {
    v = v.trim();
    if (!v) return 'Email address is required.';
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v)) return 'Please enter a valid email.';
    return null;
  },
  mobile: v => {
    v = v.trim();
    if (!v) return 'Mobile number is required.';
    if (!/^\+?[\d\s\-\(\)]{7,20}$/.test(v)) return 'Enter a valid phone number.';
    return null;
  },
  message: v => {
    v = v.trim();
    if (!v)           return 'Message is required.';
    if (v.length < 10)return 'Message must be at least 10 characters.';
    return null;
  },
  consent: chk => chk ? null : 'Please accept the terms to continue.',
};

function setField(id, err) {
  const inp = document.getElementById(id);
  const eEl = document.getElementById('e-' + id);
  if (!inp || !eEl) return;
  if (err) {
    inp.classList.add('err'); inp.classList.remove('ok');
    eEl.querySelector('span').textContent = err;
    eEl.classList.add('on');
  } else {
    inp.classList.remove('err');
    if (id !== 'consent') inp.classList.add('ok');
    eEl.classList.remove('on');
  }
}
function clearField(id) {
  const inp = document.getElementById(id);
  if (inp) inp.classList.remove('err','ok');
  const e = document.getElementById('e-' + id);
  if (e) e.classList.remove('on');
}

['fullName','email','mobile','message'].forEach(id => {
  const el = document.getElementById(id);
  el.addEventListener('blur',  () => setField(id, V[id](el.value)));
  el.addEventListener('input', () => clearField(id));
});

/* ── Rate limit (client-side 30s) ──────────────────────────── */
let lastSub = 0;

/* ── Form submit ───────────────────────────────────────────── */
document.getElementById('mForm').addEventListener('submit', async e => {
  e.preventDefault();

  const now = Date.now();
  if (now - lastSub < 30000) {
    toastr.warning('Please wait ' + Math.ceil((30000-(now-lastSub))/1000) + 's before trying again.', 'Slow down!');
    return;
  }

  const fields = {
    fullName: document.getElementById('fullName').value,
    email:    document.getElementById('email').value,
    mobile:   document.getElementById('mobile').value,
    company:  document.getElementById('company').value,
    message:  document.getElementById('message').value,
    consent:  document.getElementById('consent').checked,
  };

  // Validate all
  let hasErr = false;
  ['fullName','email','mobile','message','consent'].forEach(id => {
    const val = id === 'consent' ? fields[id] : fields[id];
    const err = V[id](val);
    setField(id, err);
    if (err) hasErr = true;
  });

  if (hasErr) {
    toastr.error('Please fix the highlighted fields.', 'Validation Error');
    document.querySelector('.err')?.scrollIntoView({ behavior:'smooth', block:'center' });
    return;
  }

  // Loading state
  const btn = document.getElementById('subBtn');
  const txt = document.getElementById('btnTxt');
  btn.disabled = true;
  txt.innerHTML = '<div class="spin"></div> Submitting…';

  const payload = {
    fullName:  fields.fullName.trim(),
    email:     fields.email.trim().toLowerCase(),
    mobile:    fields.mobile.trim(),
    company:   fields.company.trim(),
    message:   fields.message.trim(),
    timestamp: new Date().toISOString(),
    userAgent: navigator.userAgent.substring(0, 150),
  };

  try {

    // Get the CSRF token from the meta tag
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      
    const res  = await fetch(window.location.href, {
      method: 'POST',
      headers: {
        'Content-Type':     'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN':     csrfToken   // <--- ADDED THIS HEADER
      },
      body: JSON.stringify(payload),
      signal: AbortSignal.timeout(12000),
    });

    const data = await res.json();

    if (res.ok && data.success) {
      lastSub = Date.now();
      document.getElementById('mForm').style.display = 'none';
      document.getElementById('sov').classList.add('on');
      toastr.success(
        data.emailSent
          ? 'Confirmation sent to <strong>' + payload.email + '</strong>'
          : 'Saved! (Email delivery not configured on server)',
        "You're on the list! 🎉",
        { timeOut: 7000 }
      );
    } else {
      throw new Error(data.message || 'Server error.');
    }

  } catch (err) {
    if (err.name === 'TimeoutError') {
      toastr.error('Request timed out. Please try again.', 'Timeout');
    } else if (!navigator.onLine) {
      toastr.error('You appear to be offline.', 'No Connection');
    } else {
      toastr.error(err.message || 'Something went wrong. Please try again.', 'Error');
    }
  } finally {
    btn.disabled = false;
    txt.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="M22 2L11 13"/><path d="M22 2L15 22 11 13 2 9l20-7z"/></svg> Notify Me When You\'re Back';
  }
});

/* ── Reset ─────────────────────────────────────────────────── */
function resetForm() {
  document.getElementById('mForm').reset();
  document.getElementById('mForm').style.display = '';
  document.getElementById('sov').classList.remove('on');
  ccEl.textContent = '0 / 500'; ccEl.className = 'cc';
  ['fullName','email','mobile','message','consent'].forEach(clearField);
}

/* ── Online / Offline ──────────────────────────────────────── */
window.addEventListener('offline', () => toastr.warning('You are offline.', 'Connection Lost'));
window.addEventListener('online',  () => toastr.success('Connection restored!', 'Back Online'));
</script>

</body>
</html>