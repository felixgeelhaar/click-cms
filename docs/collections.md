# Collections

The word "collection" does not explain itself, and this is the part of the admin
people most often get wrong. It is worth ten minutes, because once the idea lands
the screens are straightforward.

## What a collection is, and why it is not a page

A **page** is a place. It has an address of its own, you build it section by
section, and you decide where it sits in the navigation. Your About page is a
page. So is Contact. There are not many of them, they are all different, and each
one was put there on purpose.

A **collection** is a set of things that are all the same shape as each other.
Every blog post has a title, a date, a body. Every team member has a name, a role,
a photo. There may be three of them today and ninety in two years, and nobody
wants to build ninety pages by hand — or add ninety items to the navigation.

So the deal is different. You do not build a blog post. You **fill in the same
handful of fields you filled in last time**, and the site puts them where they
belong.

Here is the distinction in the form that matters day to day:

| | A page | A collection entry |
|---|---|---|
| How many | A few, each different | Many, all alike |
| You decide the layout | Yes — you add and arrange sections | No — you fill in fields |
| In the navigation | Yes, if you add it there | No — entries are listed automatically |
| Made by | Building it | Filling in a form |

That third row is the one to hold on to. **A blog post is not something you put in
the menu.** You do not add "Our new workshop bench" to the row of links across
the top of your site, and you would not want to — next month there would be
eleven of them up there. A post is one of many similar things, and the site lists
them for you. What goes in the menu, if anything, is the one page that holds the
listing.

If you find yourself about to build a page for the fifth item of something, stop:
that is a collection.

## The collections screen

Click **Collections** in the left-hand menu. You get one card per kind of thing
your site keeps, with its name, a sentence describing it, and how many entries it
has. Click a card to see its entries.

![The Collections screen showing two cards: "Blog posts — Articles and news, each published on its own", with 2 entries, and "Team members — People to show on an About or Team page", with 3 entries.](images/collections.png)

Which collections you have was decided when your site was built, and there is no
**+ New collection** button — a new *kind* of thing is a job for whoever looks
after the site, not something you can add here. What you add is entries.

The example site that ships with the CMS has two, and they are worth walking
through because they are different in an instructive way.

## Blog posts

*Articles and news, each published on its own.* Newest first.

| Field | What goes in it |
|---|---|
| **Title** — required | The name of the post. This is what shows in the entries list, and it is what the address is worked out from. |
| **Author** | Points at one of your **Team members**. See [References](#references) below. |
| **Date** | The date of the post. This is also what the list is sorted by, newest first. |
| **Excerpt** | *A short summary shown in listings.* One or two sentences. Where a listing shows a taste of each post rather than the whole thing, this is the taste. |
| **Cover image** | The picture that represents the post. Picked from your [media library](pictures.md). |
| **Body** — required | The post itself, with formatting — headings, bold, links, lists. This uses the same toolbar as a page's text: see [the rich-text toolbar](editing.md#formatting-text). |
| **Related posts** | Points at other **Blog posts** — as many as you like, in an order you choose. |

## Team members

*People to show on an About or Team page.* No particular order; the most recently
edited come first.

| Field | What goes in it |
|---|---|
| **Name** — required | The person's name. This is what shows in the entries list, and what other entries see when they point at this one. |
| **Role** | Their job title, in whatever words your site uses. |
| **Photo** | Their photograph, picked from your media library. |
| **Bio** | A short plain-text description. No formatting here — it is a plain box, not a rich-text one. |

Notice how the two differ. A team member is a small, flat record: four fields,
none of them pointing anywhere else, and no date. A blog post is a document with a
publication date, a formatted body, and two fields that **point at other
entries**. Same idea, very different weight — which is why "collection" is a word
about shape rather than about blogging.

## References

Two of the blog post fields — **Author** and **Related posts** — do not ask you to
type anything. They ask you to point at something that already exists.

**Author** offers you a list of your team members, and you pick one. You are not
typing "Mara Ellis" into a box; you are saying *this post's author is that person
over there*. The difference matters in three ways:

- **You cannot get it wrong.** No misspelling, no "Mara Elis" on one post and
  "Mara Ellis" on another.
- **If that thing changes, the link follows it.** Correct a team member's name or
  swap their photo once, and every post that points at them shows the new version.
  You do not go round the posts fixing them.
- **It is a real connection, not a coincidence.** The site knows those posts
  belong to that person, which is what lets it do something with the fact.

**Related posts** works the same way but holds several. You add posts one at a
time from a list, each one appearing as a small labelled item. Beside each is an
up arrow, a down arrow and a cross, so you can put them in the order you want and
take one out again. **The order you choose is the order they are delivered in**, so
put the most relevant first. A post already in the list is not offered again, so
you cannot add the same one twice.

An empty reference field is fine. A post with no author and no related posts is a
perfectly valid post.

### When the thing you pointed at goes away

Nothing stops somebody deleting a team member that posts point at, and the CMS
does not pretend otherwise. What happens is this:

- The reference is left pointing at something that is not there. Where the entry
  editor would normally show a name, it shows the missing item's address followed
  by **(missing)**.
- **Your entry is still valid and still saves.** A post does not become
  unsaveable because its author was deleted — you would then have a post you
  could not fix, which is worse than the problem.
- On the public side, a reference to something that is not published does not
  resolve at all. Visitors are never shown the existence of unpublished work.

So if you see *(missing)* against a reference, somebody removed what it pointed
at. Pick a replacement or clear the field.

### Referenced by

At the bottom of an entry's editor there is a **Referenced by** panel, which
appears only when something points **at** this entry. Open a team member and it
lists the posts that name them as author.

It is there to answer "what will I break?" before you delete something. It does
not stop you deleting — read it, then decide.

## Working with entries

Click a collection card and you get its entries, newest or most-recently-edited
first depending on the collection. Each row shows the entry's title, its address
underneath, a badge saying where it stands, and **Edit** and **Delete** buttons.
**+ New entry** starts one.

Unlike the Pages list there are no filter tabs and no search here — it is one
straight list.

### Making a new entry

**+ New entry** gives you an empty form with the collection's fields, plus one
extra box at the top: **Slug**, marked *Optional — derived from the title if left
blank*. The slug is the entry's own address. Leave it empty and the site works one
out from the title, which is almost always what you want.

**The slug box appears only while you are creating.** Once the entry exists its
address is fixed and there is no field for it — the same reasoning as with pages,
and for the same reason: an address that changes breaks every link anybody saved.

Fill in the fields. The ones with a **red asterisk** are required, and the entry
will not save without them — you will get a message against the field naming it,
in the same way a page does. See
[Required fields](editing.md#required-fields-and-the-red-asterisk).

Then **Save**. And then, separately:

## Publishing an entry

Entries publish independently, and saving is not publishing here either.

This works exactly as it does for pages, and it catches people in exactly the same
way.

**Save** keeps your work in the admin. **Publish** puts that one entry in front of
the public. Every entry has its own state, quite independent of every other entry
and of any page. Publishing one post does not publish the next.

The badge on an entry says where it stands:

| Badge | Meaning |
|---|---|
| **Draft** | Never published. Nobody outside the admin has seen it. |
| **Live** | Published, and what the public has matches what is here. |
| **Unpublished changes** | Published, but you have saved edits since that are not out yet. |
| **Taken down** | Published once, then withdrawn. |

The strip at the top of the entry editor carries the buttons: **Publish** — or
**Publish changes** when there are saved edits waiting — and **Unpublish**, which
withdraws the entry from the public site without deleting anything. The wording is
slightly different from the page editor, which says *Take down* for the same
action; the resulting badge is the same.

The messages are plain about it. After saving: *Saved. This is not on the public
site until you publish.* After publishing: *Published. This entry is now on the
public site.*

Everything else you rely on with pages is here too:

- **Version history**, below the fields, with every save timestamped and a
  **Restore** button on the older ones. Restoring changes your working copy, not
  the live entry — *the live entry is unchanged until you publish*.
- **Preview**, which mints a link showing the draft, so you can hand it to
  somebody before it goes live.
- **Delete**, which is the one that is not easily undone. It asks *Delete this
  entry? This cannot be undone.* If you want an entry off the public site but
  kept, **Unpublish** it instead.

If your account cannot publish — see
[What your account can do](roles.md#author) — the buttons are not there, and the
strip tells you to ask. Save, and say it is ready.

## Where entries appear on the public site

This is the honest answer, and it is a bit less tidy than the rest of the page.

**It depends entirely on how your site was built**, and there is no setting here
that changes it. A collection holds and publishes your content; **what displays
it is a separate decision made when the site was made.** Broadly there are two
arrangements:

1. **A separate front end.** The CMS holds the content and hands it out; a
   separately-built website reads it and decides how a post looks, what the
   listing page shows and what a post's address is. Sites built this way often
   look nothing like the admin's own themes.
2. **Section designs made for the purpose.** A page can carry a section whose job
   is to list a collection — a "latest posts" strip, a team grid.

Two things follow, and they are worth knowing before somebody asks you why their
new post is not showing:

- **None of the six section designs that ship with the example site lists a
  collection.** Rich text, Media and text, Card grid, Facts, Call to action and
  Contact form all hold their own content. So on the shipped example site,
  publishing a blog post does **not** make it appear anywhere on a page. The
  Journal page in that site's menu is a single block of text, and it says as much
  — it points you at *Blog posts* rather than listing them.
- **There is no automatic address for an entry.** The CMS does not, by itself,
  serve a post at `/journal/my-post`. If your site has addresses like that, they
  come from the front end that was built for it.

So the question to ask whoever built your site is: **"where does a published blog
post show up, and at what address?"** It has a definite answer, it takes them one
sentence, and it is not something this page can guess on your behalf. Write it
down somewhere your colleagues will find it.

What you can always rely on: an entry you have published **is** published, and
whatever displays your site can see it. If it is not appearing, the thing to check
first is the badge — *Draft* and *Unpublished changes* both mean the public does
not have it yet.

## Quick answers

**I published a post and the site looks the same.** Check the badge says *Live*.
If it does, the question is where posts are meant to appear — see the section
above.

**I want a post in the top navigation.** You almost certainly do not, and a
collection entry is not something the menu can point at. What goes in the menu is
the page that holds the listing, and adding it there is an administrator's job —
see [Menus](admin-tour.md#menus).

**The Author dropdown is empty.** There are no team members yet. Add one under
**Team members** first, then come back.

**A reference says (missing).** What it pointed at has been deleted. Pick
something else, or clear the field.

**Can I add a new field to blog posts?** Not from the admin. The shape of a
collection is set up by whoever built the site.

**Can I make a new collection?** Not from the admin, for the same reason. There is
no button for it, which is the screen telling you so rather than hiding it.
