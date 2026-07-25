# Editing a page

Changing the words on a page is the thing you will do most often. It takes four
steps: find the page, open it, change what you want, save. Nothing reaches the
public site until you publish, so you can work at your own pace.

If you have not signed in yet, start with [Getting started](getting-started.md).
If you are not sure which buttons your account gets, see
[What your account can do](roles.md).

## Find the page

Click **Pages** in the left-hand menu. You get a list of every page on your
site.

![The Pages screen listing four pages — About the workshop at /about, Contact at /contact, Rivet & Oak at /home and Journal at /journal — each with a green LIVE badge, a published date, and Edit and Delete buttons. Above the list are filter tabs: All, Live, Edits pending, Never published and Taken down. A "+ New Page" button sits top right.](images/pages-list.png)

Each row tells you four things:

- **The title** — the name of the page.
- **The address** — such as `/about`. That is what a visitor types or clicks.
- **A badge and a published date** — where this page stands, and when it last
  went live. The badges read **Live**, **Live, edits pending**, **Never
  published** or **Taken down**.
- **Edit** and **Delete**.

The tabs above the list narrow it down, and each carries a count:

| Tab | Shows |
|---|---|
| **All** | Every page. |
| **Live** | Pages the public can see now. |
| **Edits pending** | Pages with saved changes that have not been published. |
| **Never published** | Pages you have made but never put live. |
| **Taken down** | Pages removed from the public site but still kept here. |

**Edits pending** is the tab to check if you are ever unsure whether you
finished something.

### A word about Delete

**Delete** removes the page itself. It is not the way to hide a page from
visitors for a while — for that, open the page and take it down, which keeps
everything. See [Publishing your changes](publishing.md).

The Delete button is shown to everybody, but not everybody may use it. An editor
can delete only pages they created themselves; an author likewise. If your
account may not delete a particular page, the site refuses when you click and
tells you so — nothing is harmed by finding out this way.

## Open it

Click **Edit** on the row you want. The page editor opens.

![The top of the page editor. A banner reads "Not published — this page has not been saved yet", the Title field contains "Rivet & Oak", and the Slug field contains "home" with the note that the slug is part of the page's address and cannot be changed here. Below, a Sections heading and the first section, "Media and text".](images/edit-page.png)

At the top of the editor is a **banner** telling you where this page stands: on
the public site, not saved yet, or saved with changes waiting. It is the first
thing to read and the last thing to check.

Below it:

- **Title** — the name of the page. Changing it changes the heading visitors
  see, and the name in your Pages list.
- **Slug** — the last part of the page's web address. **It cannot be changed
  here**, and the editor says so. Addresses that change break other people's
  links, so this is deliberate. If an address really must change, ask whoever
  looks after the site: they can change it and put a **redirect** in place so
  the old address still works. See [Redirects](admin-tour.md#redirects).

## What a section is

A page is built from **sections** stacked one above the other — a banner, then
some text with a picture, then a row of figures, then a button. Each section
uses a **design** the site provides, and each design comes with its own set of
fields to fill in. The editor says as much above them: *add sections to build the
page. Each one uses a design the site provides.*

You do not lay anything out by hand. Fill in the fields, and the design puts
them in the right place, on a laptop and on a phone alike. That is the trade: you
give up control of the layout, and in exchange you cannot break it.

![A section in the editor. Above, an image field with a thumbnail of workshop.svg, a Change image button, an Image description field and an Image position dropdown. Below it, section 2, "Facts", with its heading field and a numbered list of figures — each with its own move up, move down and remove buttons.](images/section-controls.png)

Every section has a header strip with:

- **A drag handle** — hold it to drag the whole section to a new position.
- **A number** — where it sits on the page, counting from the top.
- **Its design name**, such as *Facts* or *Call to action*.
- **Buttons to move it up, move it down, duplicate it, and remove it.**

**Duplicate** is more useful than it sounds. To add a second item that looks
like one you already have, copy it and change the words, rather than starting
from an empty section.

## Which design does what

Sixteen designs ship with the example site. That is more than anybody wants to
read through, so they are grouped below by **what you are trying to do**. Find
the group, scan the middle column until something matches the job, and stop.

Your own site may have fewer of these, or others added when it was built. The
**Choose a design…** dropdown is the true list; these are what comes in the box.

Two things about the right-hand column, which lists the fields you will be asked
for:

- **A field in bold is required.** The section will not save without it — see
  [Required fields](#required-fields-and-the-red-asterisk).
- Where a design holds a **repeating list**, the fields of one row are named
  after the word *each*, with how many rows are allowed in brackets.

### Say something

For words on a page.

| Design | Reach for it when | Fields |
|---|---|---|
| **Rich text** | You have a paragraph, an explanation, a story about the business. The workhorse. | Heading, **Body**, Width: narrow, wide or full |
| **Heading and lead-in** | A long page needs breaking into parts, with a sentence or two introducing what follows. | **Heading**, Lead-in |
| **Media and text** | A picture and some words explain each other — the workshop floor beside a description of what happens on it. | Heading, Body, **Image**, Image description, Image position: left or right |
| **Quote** | Somebody said something good about you and you want it in their words. | **The words**, **Said by**, Role or company, Portrait, Portrait description |

### Show something

For pictures, film and figures.

| Design | Reach for it when | Fields |
|---|---|---|
| **Gallery** | You have several pictures and little to say about each one — finished work, an event, a place. | Heading, Intro text, Columns: 2, 3 or 4, then each picture: **Picture**, Caption (1 to 24) |
| **Video** | You have a film to put on the page. | Heading, **Video**, Still frame, Caption |
| **Logos and accreditations** | You want to show partner, association or certification marks in a strip. | Heading, Columns: 2, 3 or 4, then each mark: **Mark**, **Name**, Link (1 to 24) |
| **Facts** | There are numbers a visitor should notice: a founding year, a count, a standard you meet. | Heading, then each figure: **Figure**, **Caption** (1 to 6) |

### List something

For a set of things that are all the same shape as each other.

| Design | Reach for it when | Fields |
|---|---|---|
| **Card grid** | You have a set of similar small things: services, product types, places you work. | Heading, Introduction, Columns: 2, 3 or 4, then each card: **Title**, Text, Image, Link (1 to 12) |
| **Details list** | You have pairs of label and value: opening hours, contact details, specifications. | Heading, Intro text, then each row: **Label**, **Value** (1 to 24) |
| **Questions and answers** | You are asked the same questions over and over and would rather answer them once. | Heading, Intro text, then each question: **Question**, **Answer** (1 to 24) |
| **People** | You want to introduce your team, with photographs and a line each. | Heading, Intro text, Columns: 2, 3 or 4, then each person: Photograph, **Name**, Role, Short biography, Email address (1 to 24) |
| **Prices and plans** | You want what you offer and what it costs set out side by side. | Heading, Introduction, Columns: 2, 3 or 4, then each plan: **Plan name**, **Price**, Who it is for, What is included, Highlight this plan (1 to 4), then Button label, Button link |
| **Collection list** | The things you want listed are blog posts or team members — a [collection](collections.md), which the site lists for you. | Heading, Introduction, **Collection**, How many entries, Order |

### Ask for something

For getting a visitor to do something next.

| Design | Reach for it when | Fields |
|---|---|---|
| **Call to action** | Somebody has read enough and should now book, buy, ring or visit. | **Heading**, Text, **Button label**, **Button link** |
| **Contact form** | People should be able to write to you without an email address sitting on a public page. | **Heading**, Intro text, **Name field label**, **Email field label**, **Message field label**, **Submit button label**, Confirmation message, Destination note |

### Notes on particular designs

The tables have no room for the handful of things that catch people out.

**Rich text — Width.** *Narrow* is the default and the right answer for reading;
a long line of text is tiring to follow. Reach for *wide* or *full* only when
what you are showing is not prose.

**Rich text is the wrong home for opening hours.** Anything that is really a set
of labels and values — Monday against 9:00–17:00, Telephone against a number —
belongs in a **Details list**, which lines the two columns up for you and keeps
them lined up when somebody adds a row.

**Media and text — alternate them.** If you have several of these in a row,
setting the image position to left, then right, then left stops the page looking
like a form. Fill in the **Image description** as well; it takes thirty seconds
and it is what a screen reader reads. See
[Pictures and video](pictures.md#write-a-description-this-one-matters).

**Card grid — count before you choose columns.** Four cards in three columns
leaves one on its own on the second row. And if you find yourself wanting a
thirteenth card, or cards that need addresses of their own, what you actually
want is a [collection](collections.md).

**Facts — keep the figure short.** It is set large, and a sentence in that slot
looks like a mistake. *2013*, *50+*, *ISO 9001*. The explanation goes in the
caption.

**Prices and plans — *What is included* is a list, not a paragraph.** It looks
like an ordinary text box, and it is not: **one line becomes one item**. Press
Enter between them rather than separating them with commas, and do not type
bullet characters of your own — each line is already becoming one. Blank lines
are dropped when you save.

**Prices and plans — *Highlight this plan*.** Each plan is either **normal** or
**featured**, and *featured* marks the one you recommend. Set it on one plan.
Setting it on all of them recommends nothing.

**Video — choose the film, then a still frame.** The **Video** field has a
**Choose video** button that lists the films in your library by name; pick one
and it shows the name, the format and the size. The **Still frame** field beside
it works the same way for pictures. What a visitor sees is covered in
[Pictures and video](pictures.md#video).

**Quote — the portrait description is not shown on the page.** It is read aloud
in place of the picture, so write it for somebody who cannot see the photograph.

**Gallery, People and Logos take their descriptions from the media library.**
There is no image-description field on those rows. The description on the
picture's card under **Media** is the one that is used — so write it there. (A
person's name and a mark's name stand in if the library has none.)

**Heading and lead-in is a signpost, not a title.** Its heading sits a level
below the page's own, which is what makes it work as a divider partway down a
long page. If what you want is a heading with words under it, that is a **Rich
text**.

**Contact form — the four labels are filled in for you.** *Your name*, *Email
address*, *Message* and *Send message* arrive already written. Change them only
if your site's tone calls for it; they are required, so do not empty them.

**Contact form — two fields worth your time.** The **Confirmation message** is
what the visitor reads after sending; "Thank you — we answer within two working
days" sets an expectation that nothing else on the page does. The **Destination
note** is private to editors and records who is meant to be reading these. It is
the field that stops a form quietly collecting messages nobody opens. What
visitors send arrives under [Submissions](admin-tour.md#submissions); nothing is
emailed anywhere unless somebody set that up separately.

**Collection list — there is nothing to write.** You choose which collection,
how many and in what order, and the site fills the rest in from the entries you
have published. If none are published yet it renders nothing at all rather than
an empty heading. See [Collections](collections.md).

## Change some text

Click into a field and type, exactly as you would in any form. There is no
special mode to switch on.

Nothing you type has left the editor yet. If you close the tab now, the change
is gone; if you save, it is kept but still not public.

### Formatting text

Fields that take formatted text — a Rich text **Body**, a blog post's **Body** —
have a small toolbar above them. There are seven buttons and nothing hidden
behind them:

| Button | What it does |
|---|---|
| **B** | Bold. Select some words first. |
| *I* | Italic. |
| 🔗 | Adds a link. It asks for the address, starting `https://`. |
| **H2** | Turns the line into a **heading**. |
| **H3** | Turns the line into a **subheading** — a heading inside a heading. |
| ¶ | Turns it back into an ordinary paragraph. |
| • | Bulleted list. |
| 1. | Numbered list. |

Two habits worth having:

- **Use H2 and H3 for structure, not for size.** A heading is a signpost saying
  "a new part of the page starts here". Somebody using a screen reader navigates
  by them, and search engines read them as an outline. Making a line into a
  heading because you wanted it bigger breaks both of those, and **B** is what you
  actually wanted.
- **Go H2, then H3 underneath it.** Do not jump to H3 for the first heading on a
  page because you prefer the size.

Plain boxes with no toolbar — a card's **Text**, a form's **Intro text**, a team
member's **Bio** — take plain words only. There is no formatting to be had there,
and that is the design rather than something missing.

### Fields that point at a page

Some fields ask for a destination rather than words: a **Button link** on a Call
to action or under a set of plans, a **Link** on a card or on a logo. You do not
type an address into these. You
**choose a page from a list of your pages**, by its title, with its address shown
alongside so that two similarly-named pages can be told apart.

If the destination is somewhere else altogether — another organisation, a booking
system, a social account — choose the **external link** option instead, and a box
appears for the full web address. It needs to start with `https://`.

Two things this buys you: you cannot mistype an address, and if a saved link
points at a page that has since been deleted, the field says so plainly instead
of leaving a dead button on your site. When that happens, pick a replacement.

The site's navigation works the same way, on the **Menus** screen — though that
one is administrator-only. See [Menus](admin-tour.md#menus).

### Repeating lists

Some designs hold a list of similar rows: **Cards** on a Card grid, **Figures**
on a Facts section, **Plans** on a Prices and plans. Each row is numbered and has
its own small buttons to move it up, move it down, and remove it.

Each list has a limit, given in brackets in
[the tables above](#which-design-does-what) — six figures on a Facts section,
twelve cards on a Card grid, four plans on a Prices and plans, twenty-four rows
on most of the rest. Every one of them needs **at least one** row. If you empty a
list completely, the save is refused rather than leaving you with an empty strip
on your page. Remove the whole section instead.

### Required fields and the red asterisk

Some fields have a **red asterisk** next to their label. Those are required,
because the design cannot do its job without them: a Call to action with no
button label has nothing to click, and a Media and text with no image is Rich
text with extra steps.

**What happens if you leave one empty:** you find out when you click **Save**,
and the save does not go through. Two things appear at once:

1. **A message across the top of the editor** saying some sections are invalid.
2. **A message against the field itself**, with a red border round it, naming
   what is missing — *Body is required.*, *Button link is required.* The message
   sits where that field's help text usually is.

Inside a repeating list the message names the row, so you know which one to fix —
*Cards entry 2: Title is required.*

Nothing is lost when a save is refused. Everything you typed is still on screen.
Fill in what it asks for and save again.

## Move, copy or remove a section

- **Move** — click the up or down arrow on the section's header. The section
  swaps places with its neighbour, and the numbers renumber themselves.
- **Duplicate** — a copy appears directly below, with the same content.
- **Remove** — the section disappears from the editor.

Removing a section removes it from the page you are editing, not from the public
site. Until you save, **Cancel** puts everything back. After you save, the
version history still has the page as it was — see
[Publishing your changes](publishing.md).

## Add a section

Scroll to the bottom of the sections. There is a box headed **Add a section**.

![The bottom of the editor: a "Call to action" section with Heading, Text, Button label and Button link fields — several marked with a red asterisk — and below it a box headed "Add a section" containing a "Choose a design…" dropdown and an Add button, followed by a collapsed "Search & social (SEO)" area.](images/add-section.png)

1. Open the **Choose a design…** dropdown.
2. Pick a design. Its description appears underneath the dropdown, so you can
   check you have the right one before committing. The list is the set your site
   was built with, so it differs from site to site.
3. Click **Add**.

The new section appears at the bottom of the page with empty fields. Fill them
in — the ones with a red asterisk are required — then use the up arrow to move
it where you want it.

If you pick the wrong design, remove the section and add another. Nothing is
spent by trying one.

## Search & social (SEO)

Near the bottom is a collapsed area called **Search & social (SEO)**. Click it
to open. What is in there controls how the page is described **elsewhere** — in a
search result, and in the little preview card that appears when somebody shares a
link to it in a message or a post. None of it changes how your page looks.

**You can leave all of it empty.** A page works perfectly well untouched, because
each field falls back to something sensible. Here is what each one is, and whether
it is worth your time:

| Field | What it is | Worth touching? |
|---|---|---|
| **Meta title** | *Shown in the browser tab and search results. Left empty, the page title is used.* | Only when the page's own title is not what you would want a stranger to read out of context. A page titled "Us" might want a meta title of "About Rivet & Oak — furniture made to be repaired". |
| **Meta description** | *The snippet search engines and social cards show under the title.* | **Yes, this one is worth it.** One or two sentences, written for somebody who has not arrived yet. If you fill in nothing else here, fill in this. |
| **Open Graph image** | *The image shown when this page is shared on social media.* | Worth setting on pages people are likely to share. A wide picture works best; see [Pictures and video](pictures.md). |
| **Canonical URL** | *The preferred address for this page, when the same content is reachable at more than one URL.* | **No.** Leave it empty. It exists for an unusual situation, and filling it in wrongly can hide a page from search results. |
| **Hide this page from search engines (noindex)** | A checkbox. | Rarely, and deliberately. See below. |

**When to tick "hide from search engines".** When you want a page to exist and be
reachable by anybody you send the link to, but not to be found by strangers
searching. A thank-you page after a form, a price list for one particular client, a
page you have not announced yet. It is not a lock — anybody with the link can
still open it — so it is privacy by obscurity and nothing stronger. For most
pages, leave it alone.

Whichever of these you change, they are part of the page like any other field:
they are kept when you **Save**, and they reach the public when you publish.

## Save

At the bottom of the editor are three buttons.

- **Cancel** — throws away everything you have changed since you opened the
  page, and leaves the page as it was.
- **Preview** — saves, then gives you a link showing how the page will look,
  without publishing it. See
  [Preview before you publish](publishing.md#preview-before-you-publish).
- **Save** — keeps your changes.

Click **Save**. The banner at the top updates to tell you the new state of the
page.

**Saving does not put your changes on the public site.** A page can be live with
older content while your saved edits wait — that is exactly what the *Edits
pending* tab lists. Putting them live is one more step, and it has its own page:
[Publishing your changes](publishing.md).

If your account is an **author**, that step is not yours to take, and the banner
says so rather than offering a button. Save, leave a note, and tell whoever
approves. See [What your account can do](roles.md#author).

## If it goes wrong

- **You have not saved yet.** Click **Cancel**. The page returns to its last
  saved state.
- **The save was refused.** Read the message against the red-bordered field. See
  [Required fields](#required-fields-and-the-red-asterisk). Nothing you typed is
  lost.
- **You have saved, and want the old version back.** Below the Save button is
  **Version history**, with every save listed and a **Restore** button on the
  older ones. See
  [Undoing a mistake](publishing.md#undoing-a-mistake-with-version-history).
- **You cannot tell whether a change is live.** Open the page from the Pages
  list and read the banner at the top. It always says.
