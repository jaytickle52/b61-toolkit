ics-parser v3.6.0 by Jonathan Goode (MIT) — https://github.com/u01jmg3/ics-parser
Changes from upstream:
1. namespace ICal -> B61Toolkit\Vendor\ICal, so it cannot clash with another
   plugin (e.g. ICS Calendar) loading its own copy.
2. ICal.php processRecurrences(): DAILY rules now honour BYDAY and BYMONTH
   limits (RFC 5545). Marked "B61 Toolkit patch". Upstream skipped them, so
   FREQ=DAILY;BYDAY=MO,TU,WE,TH,FR produced weekend dates.
The Toolkit never lets it fetch URLs itself; feeds are fetched with wp_safe_remote_get().
