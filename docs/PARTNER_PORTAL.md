# How an "internal-only" app became externally reachable

Original security posture (2013–2023): this app was reachable only from
the internal corporate network, so a 2021 penetration test's SQL
injection finding (LOAN-SEC-07) was risk-accepted with the reasoning
"low likelihood, internal network only."

In 2023, a partner integration project needed a dealer network (for the
auto-loan product this app was extended to support in 2019) to submit
applications without VPN access. Rather than build a new API, the
fastest path was to expose this app's existing web forms through a
reverse-proxy in the partner-facing DMZ, with a shared partner login in
front of it.

Nobody re-ran the 2021 pen test findings against this new exposure. The
original SQL injection issue in `apply.php` is believed to still be
present and is now reachable, in effect, from partner networks the
company does not directly control.

This is a common way "internal, low-risk" legacy systems quietly become
higher-risk over time: not because the code changed, but because the
assumptions around it did, and nobody revisited the original risk
assessment when that happened.
