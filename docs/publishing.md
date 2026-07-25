# Publishing your changes

This is the page to read carefully, because it holds the one idea that catches
everybody out.

## Saving is not publishing

**Save** keeps your work. **Publish** shows it to the world. They are two
separate actions, and doing the first does not do the second.

Think of it as a shop window. Saving is writing a new price card at the counter:
it exists, it is safe, nobody outside has seen it. Publishing is walking over
and putting it in the window.

This is why a page can be live and out of date at the same time — the public
sees the last version you published, while your newer saved edits wait. The
Pages list has a tab for exactly those pages: **Edits pending**.

It also means you can save an unfinished sentence, close your laptop, and come
back tomorrow without anyone seeing a half-written page.

The same is true of **collection entries** — a blog post, a team member. Each one
publishes on its own, quite separately from every page and from every other entry.
See [Publishing an entry](collections.md#publishing-an-entry).

## Whether publishing is yours to do

Not every account may publish, and it is worth knowing which yours is before you
need to.

- **An administrator or an editor** can publish and take down, and the buttons are
  in the banner at the top of the editor.
- **An author** cannot, and the buttons are not shown. In their place the banner
  reads *"Your account cannot publish. Ask an editor to put these changes live."*
  This is the site working as designed, not a fault: an author drafts, and
  somebody else decides it goes live.
- **A viewer** cannot save at all, let alone publish.

If you are an author, the rest of this page is still worth reading — the states,
the version history and the notes all apply to you. What differs is the last
step. [Leaving a note](#leaving-a-note) is how you hand a page over, and
[Showing an unpublished entry to somebody](#showing-an-unpublished-entry-to-somebody)
is how you hand over a blog post.

[What your account can do](roles.md) has the full picture.

## Preview before you publish

At the bottom of the editor are **Cancel**, **Preview** and **Save**.

![The bottom of the page editor showing Cancel, Preview and Save buttons, and below them a Version history panel. It explains that restoring changes the working copy rather than the live page, and lists saves by date and time, the newest marked WORKING COPY and the one below it marked SAVED, with a Refresh button.](images/save-and-history.png)

**Preview** shows you the page as it will be, without publishing anything. Use
it whenever you have changed a layout, added a section, or swapped a picture —
it costs nothing and it is the cheapest way to catch a mistake.

What the button does, in order:

1. **It saves the page first.** So what you are shown is what is on your screen,
   not the last version you saved. Worth knowing the other way round too:
   Preview is not a way to look at a change without keeping it.
2. **It makes a link**, and puts it in a box headed *Preview link ready*, with a
   **Copy** button and an **Open** button beside it.
3. **The link lasts about an hour**, and the box prints the exact time it stops
   working. After that it is a dead address — press **Preview** again for a
   fresh one.

The link is the useful part. Anybody who holds it can see the page: no account,
no password, nothing to explain. That is how a draft goes to a client or a
proofreader who has never seen the admin.

Two things it is not. It is not publishing — minting a link changes nothing about
what the public site shows. And it is no more private than the link itself, so
send it to the people you mean to and treat a forwarded one as forwarded.

While you are signed in you can skip the button altogether: put `/preview/` in
front of a page's address and you get the same thing. The page at `/about` is at
`/preview/about`. Signed out, that address answers *page not found*, exactly as
an address that never existed would.

A **viewer** account cannot make a preview link at all — see
[What your account can do](roles.md#viewer).

## Showing an unpublished entry to somebody

This section is for an **author**, and it answers the question the role creates:
if you cannot publish a blog post, how does the person who can ever see it?

The answer is that **a collection entry is readable at the address it will have
once it is published, with `/preview/` in front**. A post that will live at:

```
/blog/why-we-stopped-staining
```

can be read now, exactly as it stands, at:

```
/preview/blog/why-we-stopped-staining
```

Open that in a tab while you are signed in to the admin and you see the post
rendered as a visitor would see it — your saved draft, not whatever is currently
published. Anybody signed in to the admin can open it. Anybody who is not gets
*page not found*, the same answer an invented address gives, so the existence of
a draft is not disclosed to a stranger who guesses.

So the handover is three steps:

1. **Save the entry.** The preview shows what you last saved, so an unsaved
   sentence will not be in it.
2. **Send them the address.** The whole thing, as you would any link —
   `https://your-site.com/preview/blog/why-we-stopped-staining`. Copying it out
   of your browser's address bar is the way not to mistype it.
3. **Tell them it is ready.** Entries have no comments box — that is a page
   feature — so this part happens wherever your team already talks.

Three things to know before you rely on it:

- **Only collections that have an address of their own.** Blog posts do. Team
  members deliberately do not, so there is nothing to preview at. See
  [The address](collections.md#the-address).
- **Your reviewer needs an account.** The `/preview/…` address opens for anybody
  signed in to the admin, which covers the editor who will publish it. It is not
  a link to send outside.
- **The Preview button in the entry editor is a different thing.** It also makes
  a link, but that link hands back the entry's *content as data*, for a separate
  website that reads from this one — not a page to look at. If what you want is
  to see the entry, use the `/preview/…` address above.

## The banner at the top of the page

Open any page and the banner across the top tells you where it stands. There are
three states worth recognising:

- **Green — "Published — the public site matches what is here."** Nothing is
  waiting. What you are looking at is what visitors see. The banner also has a
  link that opens the live page in a new tab, and a **Take down** button.
- **Not published, nothing saved yet.** A new page you have not saved. Save it
  first; saving still does not publish it.
- **Saved, with changes not yet published.** Your edits are safe but private,
  and the page is in the *Edits pending* tab.

Read this banner when you arrive and again before you leave. It answers "did
that actually go live?" better than anything else on the screen.

## Publish a page

1. **Save** first. There is nothing to publish until you have.
2. Publish the page from the banner at the top of the editor — that strip is
   where a page is put live and taken down again.
3. The banner turns green and says the public site matches what is here.

To be sure, use the link in the green banner to open the live page in a new tab.

If your account is not allowed to publish, the banner says so and asks you to
tell an editor. That is not an error: some sites are set up so that one person
writes and another approves. Save your work, leave a note in the comments box
(below), and tell them it is ready.

## Look at the result

![The published page as a visitor sees it: a navigation row reading Home, About, Journal and Contact, the heading "Furniture made to be repaired", an introductory paragraph about the workshop, and a large photograph below.](images/public-site.png)

Then look at it on a phone. More than half of most visitors arrive on one, and
the layout rearranges itself to fit.

![The same published page on a phone: the navigation collapsed into a menu button, the heading wrapped onto two lines, the paragraph in a single narrow column, and the photograph filling the width below.](images/public-site-mobile.png)

## Taking a page down

**Take down**, in the banner, removes a page from the public site. Visitors stop
seeing it.

It is not a delete. The page, its content and its history all stay in the admin,
and it appears under the **Taken down** tab in the Pages list. Take a page down
when an offer has ended or the details have gone stale and you would rather show
nothing than something wrong.

**Delete**, on the Pages list, is the one that removes the page itself. Take
down first, and delete only when you are certain.

## Undoing a mistake with version history

Below the Save button is **Version history**, and it is the reason you can afford
to be brave.

Every save is listed with the date and time it happened. The newest entry is
marked **WORKING COPY** — what is in the editor now — and the one you last saved
is marked **SAVED**. Every older entry has a **Restore** button.

To go back:

1. Find the entry from before the mistake, using the timestamps.
2. Click **Restore**.
3. Look at the page. **Preview** it if you want to be sure.
4. If it is right and the page is live, publish it to put the older version back
   on the public site.

Two things make this safe, and the panel says both:

- **Restoring changes your working copy, not the live page.** The public keeps
  seeing whatever is published until you publish again. You can restore, look,
  and change your mind without a visitor ever noticing.
- **The restore is itself recorded as a new version**, so it can be undone the
  same way. There is no last chance to get wrong.

## Leaving a note

Near the version history there is a **comments** box. Use it to leave a note for
whoever reviews the page — "new opening hours, confirmed with Mara", or "photo
still to come". It is a message to a colleague, not something visitors see.

## Quick answers

**I saved but the site has not changed.** Saving is not publishing. Open the
page and check the banner at the top.

**I published and my browser still shows the old page.** Reload the page. If it
is still old after a minute or two, tell whoever looks after the site — there
are caches that can hold an old copy.

**I published something wrong, right now.** Take the page down first, so nobody
sees it while you sort it out. Then restore the earlier version from version
history and publish that.

**I edited the wrong page.** Cancel if you have not saved. If you have saved,
restore the version from before your edit — and note that you have not made
anything public by saving.

**Where do I check what is waiting?** The **Edits pending** tab on the Pages
list, and the *Edits pending* count on the dashboard.

**I cannot publish, and somebody needs to read my post first.** Save it, then
send them `/preview/` followed by the address the post will have — see
[Showing an unpublished entry to somebody](#showing-an-unpublished-entry-to-somebody).

**Somebody outside the company needs to see a page before it goes live.** Use
the **Preview** button on the page and send them the link it makes. It works
without an account and stops working after about an hour.
