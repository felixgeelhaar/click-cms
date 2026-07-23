#!/usr/bin/env bash
# Seed the real TurboScience content into the running click-cms backend, then
# switch it to headless so the front end is the only public face. Idempotent
# enough for a demo: it creates content and ignores "already exists".
#
#   docker compose -f docker-compose.turboscience.yml up -d --build
#   ./demo/seed.sh
set -uo pipefail

BASE="${CMS_BASE:-http://127.0.0.1:8080}"
CJ="$(mktemp)"
CSRF=""

j() { php -r '$d=json_decode(stream_get_contents(STDIN),true); $k=$argv[1]; foreach(explode(".",$k) as $p){$d=is_array($d)&&isset($d[$p])?$d[$p]:null;} echo is_scalar($d)?$d:json_encode($d);' "$1"; }
login() { curl -s -c "$CJ" -X POST "$BASE/api/auth/login" -H 'Content-Type: application/json' -d "{\"username\":\"admin\",\"password\":\"$1\"}" >/dev/null; CSRF="$(curl -s -b "$CJ" "$BASE/api/auth/check" | j data.csrfToken)"; }
api() { local m="$1" p="$2" d="${3:-}"; if [ -n "$d" ]; then curl -s -b "$CJ" -X "$m" "$BASE$p" -H "X-Click-CSRF: $CSRF" -H 'Content-Type: application/json' -d "$d"; else curl -s -b "$CJ" -X "$m" "$BASE$p" -H "X-Click-CSRF: $CSRF"; fi; }

echo "→ waiting for the CMS…"
for i in $(seq 1 40); do curl -fsS "$BASE/health.php" >/dev/null 2>&1 && break; sleep 1; done

echo "→ first-boot login + password change"
login admin
api POST /api/auth/password '{"currentPassword":"admin","newPassword":"TurboScience2026!"}' >/dev/null
login TurboScience2026!
[ -n "$CSRF" ] && echo "  authenticated" || { echo "  ✗ could not authenticate"; exit 1; }

echo "→ hero image"
php -r '$w=1600;$h=900;$im=imagecreatetruecolor($w,$h);for($y=0;$y<$h;$y++){$t=$y/$h;$c=imagecolorallocate($im,(int)(10+$t*24),(int)(16+$t*70),(int)(40+$t*150));imageline($im,0,$y,$w,$y,$c);}$wh=imagecolorallocate($im,255,255,255);imagestring($im,5,60,50,"TurboScience",$wh);imagejpeg($im,"/tmp/ts-hero.jpg",90);' 2>/dev/null
HERO_ID="$(curl -s -b "$CJ" -X POST "$BASE/api/media" -H "X-Click-CSRF: $CSRF" -F "file=@/tmp/ts-hero.jpg" | j data.id)"
echo "  hero: $HERO_ID"

echo "→ team members"
api POST /api/collections/team-member/entries '{"slug":"dr-elena-voss","values":{"name":"Dr. Elena Voss","role":"Head of Research","bio":"Plasma physicist turned science communicator."}}' >/dev/null
api POST /api/collections/team-member/entries/dr-elena-voss/publish '{}' >/dev/null
api POST /api/collections/team-member/entries '{"slug":"marco-liu","values":{"name":"Marco Liu","role":"Lab Engineer","bio":"Builds the rigs the experiments run on."}}' >/dev/null
api POST /api/collections/team-member/entries/marco-liu/publish '{}' >/dev/null

echo "→ homepage"
api POST /api/pages "$(cat <<JSON
{"title":"TurboScience","slug":"home","sections":[
 {"type":"rich-text","values":{"heading":"Science, accelerated.","body":"<p>TurboScience turns dense research into experiments anyone can run. Real instruments, real data, explained plainly.</p>","width":"wide"}},
 {"type":"facts","values":{"heading":"By the numbers","items":[{"value":"12,400","caption":"students taught"},{"value":"48","caption":"guided experiments"},{"value":"9","caption":"partner universities"},{"value":"100%","caption":"open data"}]}},
 {"type":"card-grid","values":{"heading":"What you can do","intro":"<p>Three ways in.</p>","columns":"3","cards":[{"title":"Run experiments","body":"Step-by-step guided labs with live measurement.","link":"/blog"},{"title":"Take a course","body":"Structured paths from mechanics to quantum.","link":"/blog"},{"title":"Join the community","body":"Compare results with labs around the world.","link":"/blog"}]}},
 {"type":"call-to-action","values":{"heading":"Start experimenting today","body":"No lab required — the first experiment runs in your browser.","buttonLabel":"Read the blog","buttonUrl":"/blog"}}
],"seo":{"title":"TurboScience — Science, accelerated","description":"Hands-on experiments and open data for curious minds."}}
JSON
)" >/dev/null
api POST /api/pages/home/publish '{}' >/dev/null

echo "→ about page"
api POST /api/pages "$(cat <<JSON
{"title":"About TurboScience","slug":"about","sections":[
 {"type":"media-text","values":{"heading":"Why we exist","body":"<p>Most people meet science as a wall of jargon. We meet them with an experiment instead.</p>","image":"$HERO_ID","alt":"TurboScience banner","imagePosition":"left"}},
 {"type":"rich-text","values":{"heading":"Our approach","body":"<p>Every claim on this site is backed by data you can download and re-run. Nothing is a black box.</p>","width":"wide"}}
],"seo":{"title":"About — TurboScience","description":"The team and the method behind TurboScience."}}
JSON
)" >/dev/null
api POST /api/pages/about/publish '{}' >/dev/null

echo "→ blog posts"
api POST /api/collections/post/entries '{"slug":"why-magnets-remember","values":{"title":"Why magnets remember","author":"dr-elena-voss","date":"2026-06-14","excerpt":"Magnetic hysteresis, explained with a paperclip and a coil.","body":"<p>Hysteresis is memory in a material. Here is how to see it on your kitchen table.</p><p>Take a paperclip, a coil of wire and a battery, and watch the field lag behind the current.</p>"}}' >/dev/null
api POST /api/collections/post/entries/why-magnets-remember/publish '{}' >/dev/null
api POST /api/collections/post/entries '{"slug":"measuring-the-speed-of-sound","values":{"title":"Measuring the speed of sound with your phone","author":"marco-liu","date":"2026-07-02","excerpt":"Two phones, one clap, and a spreadsheet.","body":"<p>You do not need a physics lab to get within 1% of 343 m/s.</p><p>Place two phones a measured distance apart, record a clap on both, and read the delay off the waveform.</p>"}}' >/dev/null
api POST /api/collections/post/entries/measuring-the-speed-of-sound/publish '{}' >/dev/null

echo "→ switch the CMS to headless (front end is now the only public face)"
api PUT /api/settings '{"headless":true}' >/dev/null

echo ""
echo "✓ seeded. Published pages: $(curl -s "$BASE/api/pages" | j meta.total), posts: $(curl -s "$BASE/api/collections/post/published" | php -r '$d=json_decode(stream_get_contents(STDIN),true);echo count($d["data"]??[]);')"
echo "  TurboScience site → http://localhost:3000"
echo "  Click CMS admin  → http://localhost:8080/admin  (admin / TurboScience2026!)"
rm -f "$CJ"
