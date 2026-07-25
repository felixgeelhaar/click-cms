# Pictures and video

Every picture and every film on your site lives in one place — the **Media**
library — and pages borrow from it. So swapping a photo is two steps: put the new
picture in the library, then point the section at it.

Most of this page is about pictures, because most of what you will upload is
pictures. Video works differently in enough ways to need
[a section of its own](#video).

## The Media library

Click **Media** in the left-hand menu.

![The Media screen, headed "7 images. Each upload is resized to: sm, md, lg, xl." It has a "+ Upload" button, a search box, a folder dropdown, a dashed area reading "Drag images here, or use the Upload button", and a grid of image cards. Each card shows a thumbnail, the file name and size, a Description field, and Copy reference and Delete buttons.](images/media-library.png)

Each picture appears as a card showing:

- **Its file name**, and underneath, its dimensions in pixels and how large the
  file is — or, for a logo saved as an SVG, *scalable vector*, because that kind
  of file has no fixed size.
- **The resized versions** that were made from it, listed by name.
- **A Description box** — the most important thing on the card, and the subject of
  its own section below.
- **Copy reference** and **Delete** buttons.

The line under the heading tells you how many files you have, and that each
upload is resized into several widths automatically. That is the site making
sure a phone is not sent a picture built for a large screen. It counts films in
with the pictures and says *images* for the lot, which is the screen being loose
with a word rather than anything being wrong; a film is not resized, and
[the video section](#video) says what happens to one instead.

**Copy reference** copies the library's own name for that file to your
clipboard. Picking a *picture* for a page is done by choosing it from the
library, so you will rarely need this — with one exception that matters: the
**Video** section's video field is a box you paste a reference into. See
[Putting a video on a page](#putting-a-video-on-a-page).

### Finding one picture among hundreds

- **The search box** finds a picture by file name. This is the reason it is worth
  giving files sensible names before you upload them: `chair-ash-spindle.jpg` is
  findable in a year, `IMG_4021.jpg` is not.
- **The dropdown beside it** narrows the grid to one folder, or to
  **Ungrouped** for pictures in no folder at all. **All folders** shows
  everything.

If a search matches nothing, the screen says so rather than looking empty:
*no images match your search. Clear the filters to see the whole library.*

## Upload a picture

Either:

- **Drag the file** from your computer onto the dashed area that says *Drag
  images here*, or
- Click **+ Upload** and choose the file. You can select several at once.

The new pictures appear in the grid. If a file is refused, the message names the
file and says why — each failure is reported separately, so one bad file in a
batch of ten does not hide the other nine.

### What the library accepts

| Kind | Formats | Largest file |
|---|---|---|
| Photographs and graphics | JPEG, PNG, GIF, WebP | 10 MB |
| Logos and line drawings | SVG | 10 MB |
| Video | MP4, WebM | 64 MB |

Anything else is refused. A PDF is not a picture, and a document is not something
the media library will store.

The kind of file is decided by looking inside it, not by its name — so renaming
something to `.jpg` will not get it in.

### If the picture is too small

After an upload you may see a note on the card telling you the picture is not
large enough for the way it might be used. It is worth reading rather than
dismissing, because it does the arithmetic for you:

> This picture is only 500 pixels wide. Phones and laptops draw two screen pixels
> for every one it has, so it will look soft wherever it is shown large. Supply a
> version about 2048 pixels wide.

The reason is that modern screens pack two of their own pixels into the space of
one, so a picture needs roughly twice the width it will be displayed at to look
sharp. Once a picture is placed into a page, the same note becomes more specific
still — it knows how wide that particular section shows the image, and says
whether what you have is enough.

**Nothing stops you using a small picture.** The note is advice, not a refusal. But
a soft photograph on a home page is the sort of thing everybody notices and
nobody mentions.

## Write a description — this one matters

Every picture has a **Description** box, and it is worth thirty seconds of your
time. The description is read aloud to people who use a screen reader, and it is
what shows on the page if the picture ever fails to load.

Write what someone would need to know if they could not see it:

- **Good:** "Jun Park sharpening a plane iron."
- **Good:** "A spindle-backed chair in ash."
- **Not useful:** "image", "photo1", "IMG_4021.jpg", "chair chair furniture
  handmade".

One plain sentence is right. There is no need to start with "A picture of" —
that is already clear.

The box saves on its own as soon as you click away from it. There is no button
to press and no confirmation, so if you have typed a description and moved on,
it is kept.

## Choosing which part of a picture matters

Click the thumbnail on a card and a small marker appears where you clicked. That
is the picture's **focal point**: the part that must stay visible if the picture
ever has to be cropped to fit a space of a different shape. The arrow keys nudge
it, so it can be set without a mouse.

This is the answer to a problem that otherwise has no answer. A wide photograph
placed in a tall space has to lose something, and by default it loses the edges —
which is fine for a landscape and unfortunate when the subject is a person
standing off to one side. Marking their face means the crop keeps it.

Two things to know:

- **Your file is not altered.** The mark is a note about the picture, not an edit
  to it, and it can be moved again at any time.
- **Whether it is used depends on the design.** A layout that shows the whole
  picture has nothing to crop and ignores the mark. It matters where a picture is
  made to fill a shape.

The middle of the picture is the starting point, which is the right guess most of
the time. Set the point when it is not.

## Put a picture into a page

Open the page from **Pages**, and find the section that holds the picture.

![A section's image field in the page editor: a thumbnail of workshop.svg with a Remove button, a Change image button below it, an Image description field reading "The workshop floor, with benches under high windows" with the note "Describes the image for screen readers and when it fails to load", and an Image position dropdown.](images/section-controls.png)

An image field shows a small preview of the picture it is using, with two
buttons:

- **Change image** — opens the library so you can pick a different one.
- **Remove** — takes the picture out of this section. It does **not** delete the
  file; the picture stays in your library, ready to use again.

Underneath is an **Image description** field for this particular use of the
picture. Fill it in for the same reasons as above.

If your library is empty, the field says so and tells you to upload on the Media
page first. Go and upload, then come back to the page.

Remember to **Save** the page afterwards, and to publish it when you are happy —
see [Publishing your changes](publishing.md).

## What makes a good picture to upload

- **In focus, and well lit.** No amount of clever layout rescues a blurry photo.
- **The subject fills the frame.** A chair photographed from across the room
  becomes a speck once the picture is scaled down.
- **Wide rather than tall**, for anything that runs across the top of a page. A
  tall photo in a wide space gets cropped, and the crop may not be where you
  would have chosen.
- **No words baked into the image.** Text inside a picture cannot be read aloud,
  cannot be searched, and goes blurry when scaled. Put the words in a text field
  instead.
- **Consistent in feel.** Pictures taken in similar light, with similar framing,
  make a page look considered.

## Keep the file size down

A photo straight off a modern phone or camera can be several times larger than
anything a web page needs. Large files matter for two reasons: they take longer
to upload, and they cost your visitors time and mobile data.

The site helps — it makes smaller copies of every picture you upload so that each
visitor gets a size suited to their screen. There are four, and their names
appear on each card:

| Name | Width |
|---|---|
| **sm** | 640 pixels — a phone |
| **md** | 1024 pixels — a small laptop |
| **lg** | 1536 pixels — a large laptop |
| **xl** | 2048 pixels — a big screen, or a phone showing it full width |

A visitor is sent whichever of these fits their screen, and never the original.
That is why the advice above is to upload something around 2048 pixels wide: it
is the largest rung, so it covers every case, and everything smaller is made from
it.

The copies are only ever made **smaller**, never larger. Upload something 800
pixels wide and you get fewer rungs, which is what the note about a small picture
is telling you.

Even with all that, start from something sensible. If your image editor offers
"export for web", "resize" or "reduce file size", use it before uploading; a
couple of megabytes is generous for a photograph.

If the server your site runs on cannot resize pictures, a banner at the top of
the Media screen says so, and uploads are kept at their original size only. That
is a message for whoever looks after the site rather than something you can fix.

## Video

The library takes **MP4** and **WebM** film, up to 64 MB, and the **Video**
section design puts one on a page. Both halves of that sentence are worth going
through, because video behaves unlike a picture at every step.

### What the library does with a film

Very little, deliberately:

- **It is stored exactly as you uploaded it.** None of the resizing described
  further up this page happens to a film. There are no *sm*, *md*, *lg* or *xl*
  copies, so a phone is sent the same file a large screen is.
- **There is no focal point and nothing to crop**, so the marker on the card has
  nothing to act on.
- **There are no dimensions to read**, so the card's width-and-height readout
  stays empty. Its file name and its size in megabytes are the two things worth
  reading on a video's card.
- **The library does not take a still frame from it**, so a film's card shows no
  thumbnail. You will find it by its name — another reason to name files
  sensibly before uploading.

The whole business of keeping a film small therefore falls to you, before you
upload. Keep clips short, and if the tool you exported from offers a smaller
setting, take it.

### Putting a video on a page

Open the page from **Pages**, and add a **Video** section — see
[Which design does what](editing.md#which-design-does-what).

It has four fields, and only the second is required:

- **Heading** — optional, as everywhere else.
- **Video** — **required**, and the one unusual field in the whole editor: it is
  a plain box rather than a picker. Go to **Media**, find the film, click **Copy
  reference** on its card, come back, and paste it in.
- **Still frame** — an ordinary picture field, with a **Change image** button
  that opens the library. This is the picture a visitor sees before they press
  play. **Set one.** Without it they see a black rectangle, and a black rectangle
  is not an invitation.
- **Caption** — optional, shown beneath the film.

Save and publish as you would any other change.

### What a visitor gets

Three promises worth knowing, because they are the difference between video that
is welcome on a page and video that is not:

- **It never plays on its own.** There is no setting to make it, and that is on
  purpose — a film starting by itself under somebody's cursor is the thing people
  close the tab over.
- **Nothing is downloaded until somebody presses play.** Until then the page
  carries the still frame you chose and nothing else, so a page with a film on it
  is no slower to open than a page with a photograph on it.
- **Once they press play, they are downloading the file you uploaded**, on
  whatever connection they are on. It arrives in pieces as it plays rather than
  all at once, so it starts quickly, but a 40 MB film is still 40 MB of somebody's
  mobile data. **Under about 20 MB is kind**, which is what the field's own help
  text says.

Two smaller things. A browser too old to play the format shows a **Download the
video** link instead, so nobody is left with a blank space. And if the film is
deleted from the library while a page still points at it, the page shows a
download link where the player was — the same rule as a missing picture, and the
same fix: put a replacement in.

## Removing a picture

There are two different removals, and it is worth keeping them apart:

- **Remove**, inside a section on a page, takes the picture out of that section
  only. The file stays in your library.
- **Delete**, on a card in the Media library, removes the file itself.

Before deleting from the library, check that no page is still using it. A page
that points at a file which is no longer there has a gap where the picture was.
If you are not sure, leave it — an unused file in the library costs you nothing.

### Clearing out several at once

Each card has a small tick box. Tick two or more and a strip appears at the top
saying how many you have selected, with **Clear** to change your mind and a
**Delete** button for the lot. It asks you to confirm first, and says how many —
*this cannot be undone*, and it means it.

Use this for a batch of obvious mistakes, not as a tidy-up of anything you are
unsure about.

## Checking your work

After publishing, open the page on your phone as well as your computer. A
picture that looks generous on a wide screen is the same picture a visitor
downloads on a train — and a phone is where most people will meet it.
