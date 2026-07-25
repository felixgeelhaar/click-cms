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

## Review

Presence says who is here and comments say what they think. Neither can say
*this is ready*, and a workflow that records approval without enforcing it is a
note-taking feature with extra steps. So review is the part of this plugin that
reaches back into core.

### It needed a new kind of hook

Until this, the plugin contract could only add: `api.routes` collects endpoints,
`web.render` transforms markup. Neither can answer *may this happen at all*, and
a review step that cannot refuse a publish is not a review step.

`content.before_publish` is that hook, and it is core's — see **Extension
points** in `core.md` for the contract. It fires from `PageService::publish()`
rather than from the HTTP handler, because publishing has several callers and a
gate the others walk around is decoration.

### The state machine

One document per page and language, `collaboration_review:{page}.{locale}`,
stored through the same content service as presence and comments and therefore
inheriting the same storage, backups and version trail.

```
(no document) ──request──▶ in_review ──approve──▶ approved ──publish──▶ published
                              │  ▲                                    
                    changes   │  │ request                            
                              ▼  │                                    
                      changes_requested          … and cancelled, from either
                                                   open state
```

Only the two open states block. `approved`, `published`, `cancelled` and *no
document at all* are alike in the only way that matters: nobody is waiting.

Three rules keep the veto honest.

**It is off until a site turns it on.** A CMS that suddenly cannot publish
because a plugin shipped is a broken CMS, and most of the sites this runs on
installed collaboration for presence. The switch is a stored setting, default
off, writable by an administrator only — an editor who could disarm the gate
could publish their own unapproved page by turning off the thing about to stop
them, which makes every approval in the system decorative.

**It only blocks a review that was started and not finished.** A page nobody
sent for review publishes exactly as before. Deciding that *everything* must be
reviewed is an editorial policy, and a plugin does not have the standing to
impose it by being installed.

**Nobody approves their own request, including an administrator.** That is the
entire content of the word. There is no override, because an override that
exists becomes routine and then the recorded approvals mean nothing; a site
where one person writes and publishes should leave the gate off, which is what
the off switch is for. An administrator who needs to unstick a real review —
the named reviewer has left, the page is blocked — can *cancel* it, which
records "cancelled by X" and can never be mistaken afterwards for consent
somebody gave. Asking for *changes* on your own request is allowed: finding more
work to do is not consenting to your own change.

The named reviewer is a hint, not a lock. Naming Hanna does not stop Ada
approving, because a review that blocks on one person being available is a
review that gets bypassed. It is recorded so that something can be built on it.

### Who may review

Requesting needs the same reach as the rest of collaboration: `content.edit.any`.
An author who may only touch their own drafts has no business in another page's
review.

Deciding, and publishing a release, needs `content.publish` on top of it. An
approval is a licence to publish and a release *is* publishing, so the bar is
"could this person have published it themselves" — anything less lets an account
approve a change into production it was not trusted to put there directly. In
the shipped role map editors and administrators hold both and nobody else holds
either, so today the two bars refuse the same people. It is written as two
questions anyway: the role map is data, and the reason each half is required
outlives the current values.

### Publishing together

Publication is per page and per language, so a change spanning four documents
goes live in four acts and the site is half-updated in between. A release
narrows that to one request over an explicit set.

The important part is what it does when the set is not ready: **it refuses the
whole set**, naming every page that is not ready rather than the first, and
publishes none of them. Publishing the approved half of a release is exactly the
half-updated site above, not a partial success. Pre-flight asks every question
that can be asked without changing anything — the page exists, the caller may
publish it, no plugin objects — for every page, before the first publish.

Then it publishes through `PageService::publish()`, the same path a single
publish takes, so another gating plugin's veto is not bypassed by calling this
endpoint instead.

**Nothing is rolled back if a publish fails mid-set.** Every page passed the
pre-flight, so a failure there is storage failing, and un-publishing the pages
that already succeeded would take down pages that were live and correct before
the release started — turning a partial update into an outage. What comes back
instead is the exact list of what did and did not go out.

Note that this does *not* couple languages. A release is a set somebody chose;
it is not "publish this page in every language". The distinction is the one
above: coupling would push an unreviewed German translation live because
somebody approved the English.

### Deliberately not built

**Notifications.** There is no mail transport in this project and adding one
breaks the zero-dependency rule. Review state is *readable* instead — an
endpoint lists every review still open, each with who asked, who was asked, what
was said and the full decision trail — so a notifier is something that can be
added later against a stable record rather than something the workflow was
designed around.

**Admin UI.** API and storage only. What a UI would need: a review panel on the
page editor showing the current state and its trail, with request / approve /
ask-for-changes actions; the requester's own view of what they are waiting on;
an "open reviews" list for whoever reviews; a publish control that explains a
`409` as an editorial state rather than an error; and a release screen that
lists the chosen pages with each one's readiness, refusing to submit until all
are green.

**Live cursors.** Still not planned, and not a gap. Polling presence is the
deliberate ceiling — see the transport argument above.
