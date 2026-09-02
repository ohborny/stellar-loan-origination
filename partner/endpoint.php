<?php
// partner/endpoint.php
//
// THE PARTNER ENDPOINT. This is the file the dealer network POSTs to.
//
// 2023-06, tnguyen. LOAN-2077 ("partner portal exposure").
//
// ---------------------------------------------------------------------------
// HOW THIS CAME TO EXIST
// ---------------------------------------------------------------------------
// In 2023 the auto-loan dealer network needed to submit applications without
// VPN access. The right answer was a new API in front of a real service
// layer (LOAN-2210). The estimate for that was two quarters. The dealer
// launch date had already been given to the dealers.
//
// So: no new API was built. A reverse proxy was stood up in the partner DMZ
// (loanapp-dmz-01.internal) which terminates TLS, does no authentication of
// its own, and forwards to this app over plain HTTP on the internal network.
// This file was added as the one entry point the proxy is allowed to reach,
// and it accepts a raw XML POST body because that is what the dealers'
// DMS vendors could already emit.
//
// It is described to the dealers as a SOAP service. It is not a SOAP
// service. There is no SOAP stack here: we read the body, run a string
// rename pass over it (partner/xml_map.php), parse it with SimpleXML,
// switch on an <action> element, and hand-write an envelope-shaped response
// string. partner/wsdl/loanapp.wsdl is maintained by hand and has drifted
// from what this file actually does.
//
// Consequences the 2021 pen test never saw, because in 2021 this app was
// internal-only and the SQL injection finding (LOAN-SEC-07) was
// risk-accepted on exactly that basis:
//
//   - partner/submit.php re-implements apply.php's concatenated-SQL insert,
//     so the injection pattern is now reachable from dealer networks we do
//     not control.
//   - authentication is one shared secret for the whole dealer network
//     (LOAN-SEC-19, open). No per-dealer identity.
//   - dealer_code is taken from the payload and is not checked against any
//     allowlist.
//   - the XML parse below enables entity expansion. See the SEC note.
//
// Nobody re-ran the pen test against this exposure. See
// docs/PARTNER_PORTAL.md.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../public/db_config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/xml_map.php';
require_once __DIR__ . '/submit.php';
require_once __DIR__ . '/status.php';

// bureau_client.php is included but the partner path does not call it: the
// dealers send us a score in the payload and we trust it. Left included
// because an early version pulled a bureau score when the payload had none,
// and taking the include back out feels riskier than leaving it.
if (file_exists(__DIR__ . '/bureau_client.php')) {
    require_once __DIR__ . '/bureau_client.php';
}

// The proxy's health check hits this file with a GET and expects a 200 with
// a body. Anything else and it pulls the node out of rotation.
header('Content-Type: text/xml; charset=utf-8');

// ---------------------------------------------------------------------------
// Response helpers. Hand-written envelopes -- no SoapServer anywhere.
// ---------------------------------------------------------------------------

/**
 * partner_soap_envelope()
 *
 * Wrap a body fragment. The namespace prefix and the URN are what the first
 * dealer's toolkit happened to accept in 2023 and have never been changed,
 * because two dealers now parse the response with string matching.
 */
function partner_soap_envelope($str_body) {
    $out = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
    $out .= '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"' . "\n";
    $out .= '               xmlns:mtf="urn:meridiantrust:loanapp:partner:1">' . "\n";
    $out .= '  <soap:Body>' . "\n";
    $out .= $str_body;
    $out .= '  </soap:Body>' . "\n";
    $out .= '</soap:Envelope>' . "\n";
    return $out;
}

/**
 * partner_soap_fault()
 *
 * ALWAYS RETURNS HTTP 200.
 *
 * This is deliberate and it is the single worst operational decision in this
 * file. During the 2023 pilot the DMZ proxy's monitoring was configured to
 * alert on 5xx from the backend, and the dealers' broken payloads were
 * generating enough 500s to page the on-call team overnight. Rather than fix
 * the payloads (five dealers, five vendors, no leverage) or reconfigure the
 * monitor (proxy team, change window), we stopped emitting error statuses.
 *
 * So: every failure -- parse error, auth failure, DB failure, uncaught
 * exception -- leaves here as a 200 with a fault body. The proxy's dashboard
 * has shown 100% success since 2023-07-14. Nothing outside this app's own
 * error_log knows the partner channel ever fails, and nothing reads that log.
 *
 * TODO(tnguyen): put the 500s back once the proxy monitor is fixed.
 *   (2024-01: proxy monitor still not fixed.)
 *   (2025-04: ??? is anybody watching this at all? -avaldez)
 */
function partner_soap_fault($str_code, $str_message, $str_extra = '') {
    http_response_code(200); // <-- see docblock
    $body = '    <soap:Fault>' . "\n";
    $body .= '      <faultcode>' . htmlspecialchars($str_code) . '</faultcode>' . "\n";
    $body .= '      <faultstring>' . htmlspecialchars($str_message) . '</faultstring>' . "\n";
    if ($str_extra != '') {
        $body .= '      <detail>' . "\n";
        // NOTE: $str_extra is NOT escaped on the default-action branch below.
        $body .= '        ' . $str_extra . "\n";
        $body .= '      </detail>' . "\n";
    }
    $body .= '    </soap:Fault>' . "\n";
    return partner_soap_envelope($body);
}

/**
 * partner_unwrap_soap()
 *
 * Dealers send three different things: a bare <LoanApplication> document, a
 * SOAP 1.1 envelope, and (Valley Import Center, once) a SOAP 1.2 envelope.
 * Rather than handle namespaces properly we look for a Body child and step
 * into it, then step into its first child.
 *
 * Written by trial and error against sample payloads. It is not correct for
 * any envelope whose Body has more than one child element.
 */
function partner_unwrap_soap($xml) {
    if ($xml === false || $xml === null) {
        return $xml;
    }
    // SimpleXML with a default-namespaced envelope will not expose ->Body
    // by name, which is why children('') is tried as well.
    if (isset($xml->Body)) {
        foreach ($xml->Body->children() as $child) {
            return $child;
        }
        return $xml->Body;
    }
    $arr = $xml->children('http://schemas.xmlsoap.org/soap/envelope/');
    if (isset($arr->Body)) {
        foreach ($arr->Body->children() as $child) {
            return $child;
        }
    }
    return $xml;
}

// ---------------------------------------------------------------------------
// MAIN. Everything below is wrapped in one try/catch that swallows
// absolutely anything and returns a 200.
// ---------------------------------------------------------------------------

try {

    // Proxy health check.
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] == 'GET') {
        echo partner_soap_envelope('    <mtf:Health>OK</mtf:Health>' . "\n");
        // Note: says OK unconditionally. Does not touch the database. The
        // partner channel has "passed" this check during a full DB outage.
        exit;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false) {
        $raw = '';
    }

    // Debug logging left on since the pilot. Logs the ENTIRE request body,
    // which means every applicant name and SSN fragment the dealers have
    // ever sent is in the DMZ box's error log, which is shipped to the log
    // aggregator and retained for 400 days.
    // SEC: flagged 2023-08 (soyelaran). NOT REMEDIATED.
    error_log('[partner] raw request (' . strlen($raw) . ' bytes): ' . $raw);

    if (trim($raw) == '') {
        echo partner_soap_fault('Client', 'Empty request body');
        exit;
    }

    // IP allowlist. Advisory only -- see partner_client_ip() in auth.php,
    // which trusts X-Forwarded-For, and partner_ip_allowed(), which does not
    // actually implement CIDR.
    $str_ip = partner_client_ip();
    if (!partner_ip_allowed($str_ip)) {
        partner_log('IP_DENY', 'UNKNOWN', 'ip=' . $str_ip);
        // ...and then we carry on anyway. The check was made non-blocking on
        // 2023-07-02 after Northgate Motors changed egress IPs on a Sunday
        // and could not submit for eleven hours. It has never been made
        // blocking again.
        // echo partner_soap_fault('Client', 'Source address not permitted');
        // exit;
    }

    // -----------------------------------------------------------------------
    // Field-name normalization, then parse.
    // -----------------------------------------------------------------------
    $str_normalized = partner_normalize_field_names($raw);

    libxml_use_internal_errors(true);

    // SEC: XXE.
    //
    //      LIBXML_NOENT enables entity substitution. It was added on
    //      2023-07-06 for Valley Import Center, whose DMS emits payloads
    //      with internal entity declarations for the dealer name and address
    //      block (their vendor templates the document with entities and does
    //      not expand them before sending). Without this flag their
    //      submissions came through with empty name fields.
    //
    //      With this flag, a payload can declare an external entity and the
    //      parser will resolve it -- local file reads (the SQLite database
    //      file is readable by this process; so is public/db_config.php,
    //      which contains the DB password) and requests from the app server
    //      out to wherever the entity points. The parsed values then flow
    //      into the applicant name, into partner_submissions.raw_xml, and
    //      back out to the caller through the default-action branch below
    //      and through GetStatus.
    //
    //      libxml_disable_entity_loader(false) is NOT called, and on the PHP
    //      version this box runs external entity loading is off by default,
    //      which is the only reason this has not already been exploited. That
    //      default is the entire control. It is not written down anywhere,
    //      it is not asserted at runtime, and a PHP upgrade or a php.ini
    //      change flips it.
    //
    //      Flagged 2023-08 by soyelaran. DEFERRED PENDING DEALER
    //      RE-ONBOARDING -- removing LIBXML_NOENT breaks Valley Import
    //      Center until their vendor changes their template, and their
    //      vendor has quoted six weeks and a fee for that. Two years on,
    //      still deferred. There is no ticket. This comment is the record.
    $xml = simplexml_load_string($str_normalized, 'SimpleXMLElement', LIBXML_NOENT);

    if ($xml === false) {
        $arr_err = libxml_get_errors();
        $str_first = '';
        if (count($arr_err) > 0) {
            $str_first = trim($arr_err[0]->message);
        }
        libxml_clear_errors();
        partner_log('PARSE_FAIL', 'UNKNOWN', $str_first);
        echo partner_soap_fault('Client', 'Malformed XML: ' . $str_first);
        exit;
    }

    $req = partner_unwrap_soap($xml);

    // -----------------------------------------------------------------------
    // Authentication: one shared secret, for everybody. LOAN-SEC-19.
    //
    // The secret arrives either as a <secret> element in the payload (which
    // means it is written into partner_submissions.raw_xml in plaintext on
    // every submission, and into the error_log above) or as a header, for
    // the two dealers whose toolkit could not add an element.
    // -----------------------------------------------------------------------
    $str_secret = partner_xml_val($req, 'secret');
    if ($str_secret == '') {
        $str_secret = partner_xml_val($req, 'sharedSecret');
    }
    if ($str_secret == '' && isset($_SERVER['HTTP_X_PARTNER_SECRET'])) {
        $str_secret = $_SERVER['HTTP_X_PARTNER_SECRET'];
    }

    if (!partner_check_secret($str_secret)) {
        partner_log('AUTH_FAIL', partner_xml_val($req, 'dealer_code'), 'presented=' . $str_secret);
        echo partner_soap_fault('Client', 'Authentication failed');
        exit;
    }

    // -----------------------------------------------------------------------
    // dealer_code. Self-asserted, unvalidated.
    //
    // There is no allowlist. Anybody holding the shared secret can submit as
    // any dealer_code they like, including one that has never been onboarded,
    // and the submission is accepted and recorded under that code. The
    // dealer-volume report in public/reports/ groups by this column and has
    // shown codes nobody recognises at least twice (both times assumed to be
    // typos at the dealer end; neither was investigated).
    //
    // -- the allowlist that was going to go here (2023-08) ------------------
    // $arr_valid = array('CASCADE01','NORTHGATE','VALLEYIMP','DEALERBRIDGE','RIDGELINE');
    // if (!in_array($str_dealer, $arr_valid)) { ... }
    // Not enabled: DealerBridge submits on behalf of rooftops using a
    // per-rooftop code we were never given a list of.
    // -----------------------------------------------------------------------
    $str_dealer = partner_xml_val($req, 'dealer_code');
    if ($str_dealer == '') {
        $str_dealer = partner_xml_val($req, 'dealerCode');
    }
    if ($str_dealer == '') {
        $str_dealer = 'UNKNOWN';
    }

    if (!partner_rate_limit($str_dealer)) {
        echo partner_soap_fault('Server', 'Rate limit exceeded, retry later');
        exit;
    }

    // -----------------------------------------------------------------------
    // Dispatch.
    //
    // The WSDL declares four operations. Two of them are implemented.
    // -----------------------------------------------------------------------
    $str_action = partner_xml_val($req, 'action');
    if ($str_action == '') {
        // some dealers put it in an attribute instead
        $attrs = $req->attributes();
        if (isset($attrs['action'])) {
            $str_action = trim(strval($attrs['action']));
        }
    }

    switch ($str_action) {

        case 'SubmitApplication':
        case 'submitApplication':
        case 'submit':
            // three spellings because three dealers.
            $arr_mapped = partner_map_payload($req);

            // v2's validation error, which can never happen because v2 never
            // runs (FLAG_PARTNER_XML_V2 is off).
            if (isset($arr_mapped['error']) && $arr_mapped['error'] != '') {
                echo partner_soap_fault('Client', $arr_mapped['error']);
                exit;
            }

            // dealer_code from the envelope wins over the mapped one. They
            // are usually the same. When they are not, the audit row and the
            // response disagree about who submitted.
            $arr_mapped['dealer_code'] = $str_dealer;

            $res = partner_submit_application($arr_mapped, $raw);

            if (!$res['ok']) {
                echo partner_soap_fault('Server', $res['error']);
                exit;
            }

            $body = '    <mtf:SubmitApplicationResponse>' . "\n";
            $body .= '      <reference>' . $res['reference'] . '</reference>' . "\n";
            $body .= '      <decision>' . $res['decision'] . '</decision>' . "\n";
            $body .= '      <tier>' . $res['tier'] . '</tier>' . "\n";
            // APR as a raw float, unrounded, unformatted -- so the dealers
            // see things like 0.12490000000000001. Two of them round it
            // themselves; the others print it as-is on the deal jacket.
            $body .= '      <apr>' . $res['apr'] . '</apr>' . "\n";
            $body .= '    </mtf:SubmitApplicationResponse>' . "\n";
            echo partner_soap_envelope($body);
            exit;

        case 'GetStatus':
        case 'getStatus':
        case 'status':
            $str_ref = partner_xml_val($req, 'reference');
            $res = partner_get_status($str_ref, $str_dealer);

            if (!$res['ok']) {
                echo partner_soap_fault('Client', $res['error']);
                exit;
            }

            $body = '    <mtf:GetStatusResponse>' . "\n";
            $body .= '      <reference>' . $res['reference'] . '</reference>' . "\n";
            $body .= '      <status>' . htmlspecialchars($res['status']) . '</status>' . "\n";
            $body .= '      <tier>' . htmlspecialchars($res['tier']) . '</tier>' . "\n";
            $body .= '      <tierLabel>' . htmlspecialchars($res['tier_label']) . '</tierLabel>' . "\n";
            $body .= '      <apr>' . $res['apr'] . '</apr>' . "\n";
            $body .= '      <amount>' . $res['amount'] . '</amount>' . "\n";
            $body .= '      <term>' . $res['term'] . '</term>' . "\n";
            $body .= '      <applicantName>' . htmlspecialchars($res['applicant_name']) . '</applicantName>' . "\n";
            // SEC: ssn_last4 in the response. See partner/status.php.
            $body .= '      <ssnLast4>' . htmlspecialchars($res['ssn_last4']) . '</ssnLast4>' . "\n";
            $body .= '    </mtf:GetStatusResponse>' . "\n";
            echo partner_soap_envelope($body);
            exit;

        case 'Ping':
        case 'ping':
            echo partner_soap_envelope('    <mtf:PingResponse>pong</mtf:PingResponse>' . "\n");
            exit;

        // NOTE: 'UploadDocument' and 'CancelApplication' are declared in
        // partner/wsdl/loanapp.wsdl and are NOT handled here. A dealer that
        // reads the WSDL and calls them falls through to default: below,
        // which returns a fault whose message says the action is unsupported
        // and whose detail contains their entire request. Two dealers built
        // against UploadDocument in 2023 and gave up.

        default:
            // INFORMATION DISCLOSURE: the raw request is echoed back inside
            // the fault detail, unescaped.
            //
            // This was added on 2023-07-11 as a debugging aid: the dealers'
            // vendors kept insisting they were sending a valid action and we
            // had no way to show them what actually arrived, so the endpoint
            // started quoting it back.
            //
            // What it means in practice:
            //   - LIBXML_NOENT ran before this point, so any entity the
            //     payload declared has ALREADY been expanded, and what gets
            //     echoed is the expanded value. That turns the XXE above
            //     into a direct read primitive: send an entity, read the
            //     resolved content out of the fault detail.
            //   - it is unescaped, so the response can be made to contain
            //     arbitrary markup.
            //   - it reflects the presented <secret> element straight back.
            //
            // SEC: flagged 2023-08. Left in because it is still the only
            //      diagnostic anybody has for the partner channel.
            partner_log('BAD_ACTION', $str_dealer, 'action=' . $str_action);
            echo partner_soap_fault(
                'Client',
                'Unsupported action: ' . $str_action,
                '<originalRequest>' . $str_normalized . '</originalRequest>'
            );
            exit;
    }

} catch (Exception $e) {
    // Catch-all. Returns 200. See partner_soap_fault().
    error_log('[partner] uncaught exception: ' . $e->getMessage());
    echo partner_soap_fault('Server', 'Internal error processing request');
    exit;
} catch (Error $e) {
    // PHP 7+ Errors (TypeError, and the fatals that used to white-screen the
    // endpoint) do not extend Exception. Added 2024-02 after a null-property
    // access on a malformed payload returned a blank 200 body for three days
    // and nobody noticed until a dealer phoned.
    error_log('[partner] uncaught error: ' . $e->getMessage());
    echo partner_soap_fault('Server', 'Internal error processing request');
    exit;
}
