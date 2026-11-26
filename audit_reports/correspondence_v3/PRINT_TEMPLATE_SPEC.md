# Official print template v1

- Authenticated named route: `correspondence.print`, `/correspondence/{publicId}/print`.
- Uses a standalone Inertia Vue page, not the detail page. Server sends an allowlisted document payload, original attachment index and no task/route history by default.
- A4 portrait, 18 mm print margins, RTL Arabic, existing `/images/brand/logo.png`, restrained institutional navy/gold, grayscale print logo and black print text.
- Reference, date, sender, first recipient, classification, urgency, full original body, actual issuing account and issue date. No fabricated seal, handwriting or digital signature. This is explicitly the original issued text, not a merged correction edition.
- Letterhead uses existing versioned correspondence settings. Template source revision is code-controlled; print audit stores template version 1 and policy version. No uploaded HTML or arbitrary template execution.
- User strings use Vue text interpolation. SSR tests verify malicious subject/body/names/filenames remain escaped text.
- Optional `appendix=1` requires additional system-admin authority and records a print audit. The appendix is labelled internal and begins on a separate page. Normal recipients cannot request it.
- Print controls hidden, paragraphs may flow across pages, headings avoid orphaning, small metadata blocks avoid splitting, page-number margin box uses `counter(page)`. Page-number support depends on the print engine; no printer/browser visual verification claimed.
- The controller audits rendering and sets private/no-store and nosniff. Printing itself does not record reading or completion.

Open: institutional authorization of alternative signatories, sealed/digitally signed outputs, replacement-text editions, and retention of rendered historical PDFs. Current print regenerates from the immutable letter body and current display names/letterhead, not a stored signed artifact.
