# Visitor credentials (e.g. Working with Children Checks)

Organisations that need visitors to hold a credential, such as a Working with Children Check or
a Blue Card, can keep one record per person and credential type, and have reception check it
with the issuing body. Checking is **manual**: staff use the issuing body's own validation page.
The design leaves room for an automatic checker later, through an approved API.

## Set up

1. **Admin → Visitor workflow → Credentials.** Add a credential type, e.g.
   "Working with Children Check (WA)". Fill in:
   - **Issuing body's check page:** opened when reception checks a card.
   - **Re-check after (days):** optional. Leave it empty to re-check only at expiry.
   - **Warn before expiry (days):** default 30.
2. **Admin → Form fields.** On the question that asks for the number (short text, marked
   *Sensitive*), choose the credential type under **Credential**. Several questions can feed the
   same type, e.g. one for contractors (conditional) and one for relief staff.

## How records are filled

- **Sign-in.** The answer to a linked question updates the person's record:
  - the same number keeps its check history (spaces, dashes and case are ignored);
  - a different number starts again as *not checked*.
- **Import** (permission `Import:Credentials`, admins). Upload a CSV on the reception Credentials
  page.
  - Columns: first name, last name, number; optionally email, mobile and expiry (DD/MM/YYYY or
    YYYY-MM-DD). Common header names are recognised, e.g. "Surname", "WWCC Number" and
    "WWCC Expiry".
  - People are matched by email, then mobile; anyone not found is added.
  - Imported numbers count as *not checked*.
- **Prefill.** At the next sign-in (or the first, after an import) the number is already on
  file.
  - It is applied on the server and shown only as "saved", because it is sensitive.
  - Typing a new number replaces it.

## Reception page (`/reception/credentials`, permission `Check:Credentials`)

- **On site now, check needed:** people signed in at the site whose visitor type asks for a
  credential and whose record is any of:
  - missing;
  - not checked;
  - not valid;
  - expired;
  - due for a re-check.
- **Other credentials waiting for a check.**
- **Search** by name, email or number. Number search is exact, through a keyed hash, so stored
  numbers stay encrypted.
- **Each record shows:**
  - the number and surname, with copy buttons;
  - a link to the issuing body's check page;
  - an expiry date field, a note, and **Valid** / **Not valid** buttons.
- The check is stored with who checked it and when.

## Privacy and audit

- Numbers are encrypted at rest (`visitor_credentials.number`). `number_hash` is an HMAC keyed
  with the app key, used only for exact search.
- These events are audited without the number or the search text:
  - `credential.recorded`;
  - `credential.checked`;
  - `credential.searched` (kind of search and result count only);
  - `credential.imported`.
- Never automate a government validation page whose terms forbid automated access. That
  includes WA.gov.au and the services linked from it.

## Later: automatic validation

Possible routes, to be reviewed:

- an API approved by the issuing body;
- a third-party verification provider, for example Everproof Hoover or Certn. Confirm current
  coverage and whether its data access is authorised.

Either would record its result through `CredentialRegister::markChecked()`, the same as
reception does.
