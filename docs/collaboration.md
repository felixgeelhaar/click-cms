# Collaborating on an unpublished page

How more than one person works on the same page before it goes live, and why the
obvious implementation is the wrong one here.

This sits on top of draft-and-publish: a page's newest version is the working
copy, and publishing promotes it. Without that there is nothing to collaborate
*on*, because every save would already be live.

## What this is modelled on, and where the model stops

The reference is Google Docs: see who else is in the document, leave comments,
ask someone to review, then publish everything at once. Three separable things
are bundled in that sentence, and they have very different costs.

| | What it needs | Verdict |
|---|---|---|
| **Presence** — who else is here | knowing who is currently on a document | Build it |
| **Comments and review** — ask for a read, discuss a change | ordinary create/read/update | Build it |
| **Co-editing** — two people typing in one paragraph | operational transform or CRDTs | **Do not build** |

The first two are the useful part in a CMS. The third is where Google Docs'
actual difficulty lives, and it is not worth borrowing.

### Why no co-editing

Reconciling two people editing the same text is a research-grade problem.
Operational transform and CRDTs are both well understood and neither is small,
and the failure mode of getting one slightly wrong is that somebody's writing
silently disappears — the single worst thing a content management system can do.

The alternative is what every comparable tool does: **detect and warn**.
WordPress locks a post to one editor; Craft says somebody else is editing.
Presence already knows who is on the document, so the warning costs almost
nothing once presence exists.

An editor being told "Hanna is also editing this" solves the real problem —
two people unknowingly overwriting each other — without pretending to merge
anything.

## Transport: polling, not WebSockets

Presence needs the server to know who is currently looking at a document. There
are three ways, and only one survives this project's constraints.

| | Viable here |
|---|---|
| WebSockets | Needs a long-running process listening on a port |
| Server-Sent Events | Holds a PHP worker per viewer; ten editors can exhaust a shared host's workers and take the site down |
| **Polling, every 10–15 seconds** | **Yes** |

`core.md` lists *runs on ordinary shared hosting, no long-running process* as a
quality core must hold. It is the constraint that already decided GD over
Imagick and a file per document over a database. Managed webspace — which is
what this CMS is actually deployed on — offers no shell, no daemon, no spare
port and no reverse proxy configuration. WebSockets are not a preference there;
they are unavailable.

### The argument that actually settles it

WebSockets are perfectly possible on Docker or a VPS, so "impossible" is not the
whole answer. The better question is what they would buy.

Given that co-editing is deliberately out of scope, the only realtime features
left are presence and the is-someone-else-editing warning, and neither is
latency-sensitive. Nobody is co-typing a sentence. So the entire benefit is:

> an avatar appearing after one second instead of twelve.

Against that: a second process to supervise, a port to expose, proxy
configuration, reconnect-and-backoff logic, and a product that behaves
differently depending on where it is installed. That is a bad trade for twelve
seconds.

Polling costs a small write and a small read per editor every few seconds. For
the two or three people who edit a small business site, that is nothing.

### Leaving the door open

The plugin should talk to a presence *interface*, not to polling directly. If
live co-editing is ever adopted as a goal — a genuinely different and much
larger product — polling cannot carry it and a WebSocket transport becomes
mandatory. Keeping the seam means adding a fast path to something that already
works, rather than shipping a feature that only exists on half the installations.

## Why this is a plugin and not core

`core.md` asks: would a reasonable site turn this off? A single person running
their own site would switch off presence and review workflow immediately, and be
right to. Neither passes the test.

Draft-and-publish underneath it *is* core, because publication state is a
property of how documents are stored and nothing above storage can supply it.

This is also the first genuinely convincing use of the plugin system. Everything
promoted into core so far was promoted because it could not be a plugin;
collaboration is the case that demonstrates the line is real.

## Publishing, and the trap in it

Publication is per language, because `page:de:home` and `page:en:home` are
separate documents. Publishing one is simply publishing that document — no
cross-language machinery exists or is wanted.

The cost is a **half-updated site**: English republished, German left stale, and
nobody notices until a visitor does. The fix is visibility rather than coupling.
The editor must show, on the page being edited, what state its other languages
are in. Coupling the publish action instead would mean pushing an unreviewed
German translation live because somebody approved the English one, which trades
a visible problem for a silent one.

For the same reason the publish control should name what it is about to do —
*"Publish 4 changes"* rather than *"Publish"* — since under draft-and-publish an
editor may be releasing a week of work they no longer remember in detail.
