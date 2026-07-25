# What everything in the menu does

The menu down the left-hand side of the admin has a dozen or more items in it,
and an item you have never clicked is exactly the sort of thing that makes a
screen feel risky. This page names every one of them, in the order they appear,
and says what it is for and whether you are ever likely to need it.

Some of them you will not need, and where that is true this page says so rather
than padding out an explanation. An honest "this one is not yours" is more use
than three paragraphs about a screen you cannot open.

## The menu you see depends on your account

The menu comes in two shapes. Everybody gets **Dashboard**, a **CONTENT** group
and an **ACCOUNT** group. Administrators also get a **MANAGE** group of eight
more items, sitting between the other two.

If your menu has no MANAGE heading, the second half of this page describes
screens you cannot open — read it anyway, because it tells you what to ask for
and who to ask. [What your account can do](roles.md) explains the four kinds of
account and how to tell which is yours.

The group headings can be folded away by clicking them, so if a group looks
missing, click its heading first. The hamburger button at the top left shrinks
the whole menu to a column of icons; hovering over an icon names it.

---

## The screens everyone has

### Dashboard

The summary screen you land on after signing in. It holds four counts and
nothing else — nothing on it is clickable and nothing on it can be changed.

- **Total Pages** — how many pages exist in the admin, live or not.
- **Live** — how many of those the public can see right now.
- **Edits pending** — pages with saved changes that have not been published yet.
- **Active Plugins** — how many extra features are switched on.

**Edits pending** is the one worth glancing at. If it is not zero, somebody has
saved something and not put it live — possibly you, yesterday.

The Dashboard is also where the admin sends you if you try to open one of the
screens your account may not use. So if you type an address and land back here,
that is the reason rather than a fault.

### Pages

The pages of your site: your home page, About, Contact. This is the screen you
will use most, and it has [its own page](editing.md).

Each row shows the page's title, its address, a badge saying where it stands, and
the date it last went live, with **Edit** and **Delete** buttons on the right.
The tabs above the list — **All**, **Live**, **Edits pending**, **Never
published**, **Taken down** — narrow the list, and each carries a count.

**+ New Page**, top right, starts an empty one.

### Collections

Things you have lots of that all share a shape: blog posts, team members. A
collection is not a page and does not behave like one, which is the single most
common point of confusion in the whole admin — so it has
[a page of its own](collections.md).

If you only ever change wording and pictures on pages that already exist, you
may never open this screen.

### Media

Every picture and video on your site, in one library. Pages and collection
entries borrow from it rather than holding their own copies, which is why
swapping a photo everywhere is one upload rather than ten.

Uploading, descriptions, file sizes and what makes a good picture are covered in
[Pictures and video](pictures.md).

### Builder

A freer way of laying out a page, by arranging blocks rather than filling in a
design's fields.

**This item appears only on some sites.** It shows up when the visual builder has
been installed and your account is allowed to use it, which in practice means an
administrator. If you cannot see it, nothing is wrong.

You do not need it to change text or swap a photo. Everything in
[Editing a page](editing.md) works without it.

### Submissions

Headed **Form submissions** once you open it: *messages visitors have sent
through a contact form*.

Each message is a card showing who sent it, the address of the page they sent it
from, the date and time, and then their **Name**, **Email** and **Message**. The
newest are at the top. **Refresh** fetches any that have arrived since you opened
the screen.

Three things to know:

- **Messages only arrive here if a page has a contact form on it.** The form is a
  section design called *Contact form* — see
  [the list of designs](editing.md#which-design-does-what). No form, no
  submissions.
- **There is no read or unread mark**, and no reply button. This is a record of
  what came in, not an inbox. If these messages need answering, somebody has to
  decide who checks the screen and how often.
- **Nothing here can be deleted or exported** from this screen.

If it is empty it says so: *No submissions yet. When a visitor sends a contact
form, it appears here.*

### Profile

Under the **ACCOUNT** heading, at the bottom. It shows your **Display Name** —
the name other people in the admin see next to your work — and your **Email**.

Two honest notes about this screen:

- **Changes you make here are not kept yet.** There is a Save Changes button and
  it reports success, but your name and email are not actually updated. If either
  is wrong, ask an administrator to correct it for you on the **Users** screen.
- **Your password is not changed here.** It has its own screen, which is not
  listed in the menu. To reach it, put `/admin/password` on the end of your
  site's address — so `https://your-site.com/admin/password`. It asks for your
  current password, then the new one twice.

### The top of the screen

Across the top, from left to right:

- **The hamburger button** — shrinks the menu to icons on a computer, and opens
  it as a panel on a phone. Escape closes the panel.
- **The site name** — your site's name if one has been set, otherwise your own.
  Clicking it goes back to the Dashboard.
- **A Light / Dark button.** This changes how the admin looks to you, on this
  computer, and changes nothing about your public site. Worth knowing: the button
  shows the theme you are **in**, not the one you would switch to — so the button
  reading *Light* is the one that takes you to dark.
- **A small square with your initial in it** — opens your Profile.
- **Logout.**

---

## Administrator only: the MANAGE group

Everything from here on sits under the **MANAGE** heading, and that heading is
built only for administrator accounts. **If your menu does not have it, these
eight screens are not yours to use.** Most of them send you back to the Dashboard
if you type their address; one or two will open and then refuse to save anything.
Either way you cannot change what is on them.

That is not a gap in your setup. It is how the site keeps the settings that
affect every visitor separate from the day-to-day work of writing. The part worth
carrying away is **who to ask**: changes to the navigation, the site's look, its
name, its addresses and its accounts all need an administrator.

### Plugins

The extra features installed on this site, each with a switch to turn it on or
off. Each is a card with a name, a description, and either **Deactivate** or
**Activate**.

**This is a screen for whoever maintains the site, not for whoever writes it.**
Turning a plugin off can remove fields from your page editor or change how your
public pages render, and the effect is not always obvious from the plugin's
name. If you did not install it, leave it alone.

### Marketplace

Where new plugins come from — either from a configured registry or as a file you
upload. Again: for whoever maintains the site.

The screen opens with a warning worth repeating, because it is the real risk on
it: **a plugin runs with the same access as the CMS itself.** Registry installs
are checked against a signature before anything is written; an uploaded file is
not checked at all. Install one only if you trust where it came from.

Installing does not switch a plugin on. That happens on **Plugins**.

### Users

Who can sign in, and what each of them may do. Each row shows a person's display
name, their username and email, and a pill naming their account type, with
**Edit** and **Delete** buttons. Your own row is marked *you*, and you cannot
delete yourself.

**+ New user** asks for a username, a display name, an email, a type and a
starting password of at least eight characters. New accounts start as **Editor**.

The four types, as the screen itself describes them:

| Type | What the screen says |
|---|---|
| **Administrator** | Full access, including users and plugins. |
| **Editor** | Edits and publishes any content. Cannot manage users or plugins. |
| **Author** | Writes and edits their own content. Cannot publish. |
| **Viewer** | Read-only. |

[What your account can do](roles.md) goes through these in more detail — worth
reading before you decide which one somebody needs. The short version: give
**Editor** to staff who look after the site, **Author** to somebody whose work
should be checked before it goes live, and **Administrator** only to people you
would trust to install software.

Two limits of this screen: a username cannot be changed once the account exists,
and you cannot reset somebody else's password from here. The password field
appears only when creating a brand-new account. If somebody is locked out, a new
account is the way through.

### Redirects

Sends an old web address to a new one. The site checks this list whenever
somebody asks for an address that does not exist.

Each row has three parts and a **Remove** button:

- **From** — the old address, such as `/old-page`.
- **To** — where it should go instead: `/new-page`, or a full address elsewhere.
- **Type** — **Permanent (301)** or **Temporary (302)**. Permanent is the right
  choice when the move is settled, which it usually is; temporary when you
  expect the old address to come back into use.

**+ Add redirect** adds a row; **Save redirects** keeps the lot.

This screen matters most when a page's address changes or a page goes away. Every
link anybody has ever saved, sent or printed points at the old address, and
without a redirect all of them lead to a "not found". A redirect keeps them
working. It is also why the **Slug** field in the page editor cannot be edited
— see [Editing a page](editing.md#open-it).

### Menus

The navigation your site renders — the row of links across the top, and any
footer navigation. The dropdown at the top of the screen chooses which menu you
are editing; **New menu** plus **Create** starts another, and **Display name** is
what that menu is called.

![The Menus editor. A menu is chosen at the top, with a Display name field below it, then a heading reading Items above a numbered list of menu entries — Home, About and Journal — each entry showing a Label alongside where its link points, with small buttons beside it to move it up, move it down, add a sub-item under it, or remove it. Underneath the list are an "+ Add item" button and a "Save menu" button.](images/menus.png)

Under **Items**, each entry is numbered and has two parts:

- **Label** — the words a visitor reads.
- **Its target** — where the link goes. You **choose a page from a list of your
  pages**, by its title, with its address shown alongside so two similarly-named
  pages can be told apart. You are pointing at a page rather than typing out its
  address, which means you cannot mistype it.
- To link somewhere outside your own site, choose the **external link** option
  instead. A box appears for the full web address, starting `https://`.

If a saved item points at a page that no longer exists — because somebody deleted
it — the item says so in plain words rather than failing quietly. Pick a
replacement, or remove the item.

The small buttons beside each item move it **up**, move it **down**, **add a
sub-item** underneath it, or **remove** it. A sub-item has its own label and its
own target, chosen the same way.

**Sub-items go one level deep and no further.** You will notice a sub-item has no
"add a sub-item" button of its own — that absence is the limit.

At the bottom, **+ Add item** adds an entry and **Save menu** keeps your changes.
A menu with nothing in it is allowed; the screen says so rather than treating it
as a mistake.

The thing to remember about this screen, and it catches everybody:

> **Adding a page to your site does not add it to the menu, and publishing it
> does not either.** A new page is reachable at its own address the moment it is
> live, but it appears in the navigation only when somebody adds an item here.

### Themes

The design your public pages wear. Each installed theme is a card with its name,
version, author and description, and either the word **Active** or an
**Activate** button. One is live at a time.

Two ship with the CMS: **Default**, a light and quiet design, and **Dark**, the
same design on a dark ground. There are no colours or fonts to set here —
choosing which theme is active is the only thing this screen does.

Activating a theme changes the look of every public page at once, immediately. It
is not a per-page setting and there is no preview, so open your public site
afterwards and look.

### Settings

Two settings, each in its own panel.

**Site name** — *shown as the brand in your site's header. Leave it empty for no
brand.* This is also the name shown at the top of the admin. Type it, click
**Save**, and the screen tells you what your header now shows.

**Headless mode** — leave this alone unless you know it applies to you. Off, this
installation renders your website for visitors in the ordinary way. On, it
renders **no public pages at all**; the content is served only to a separate
front end that reads it over the site's interface. The admin looks the same
either way, which is exactly why turning this on by accident is alarming: every
public page starts answering "not found" while the admin carries on as normal.

If your site is one that has a separate front end, whoever built it will have set
this already. If you do not know, that is the answer — do not touch it.

### Updates

Installs new versions of the CMS itself. It shows the version you are running,
the policy that decides whether updates install on their own, and a **Check now**
button. If a new version is offered, you get its number, its release notes, a
**Security** badge where it applies, and **Install this update**. Below that is a
history of what has been installed and when.

**This is a screen for whoever maintains the site.** Updates are worth keeping up
with, particularly the ones marked as security releases, but installing one
replaces the code the site runs — so it belongs with whoever would fix it if
something went wrong, and ideally after a backup.

## Next

- [Editing a page](editing.md) — the work you will do most.
- [Collections](collections.md) — for blog posts, team members and anything else
  you have lots of.
