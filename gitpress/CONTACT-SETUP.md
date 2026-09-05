# Contact page

## Page shortcode

Edit the Contact page in WordPress. Paste this into its **GitPress Shortcode** metabox and choose **GitPress Managed** rendering. No new plugin ZIP is needed.

```text
[divi_github_content owner="garetshough14" repo="offlabel-custom" path="gitpress/pages/contact.html" branch="main" format="html" updated_meta="false"]
```

The page includes `[fluentform id="3"]`, not a second form. Keep GitPress safe inner-shortcode rendering enabled. The managed site header and footer remain separate. Styling is scoped to this contact page and uses the existing system-sans font and off-white/black palette. If an old result is cached, purge the contact page/GitPress cache after publishing.

## Form 3: saved with approval

Required First Name and Last Name; required Email Address; optional Order Number with `Optional` placeholder; required multiline Message with `How can we help?` placeholder. The replacement textarea retains `input_text` as its name attribute. The existing `names`, `email`, and `numeric_field` identifiers are unchanged. No entry was submitted and no email was sent during this work.

## Customer autoresponder

Open Form 3 > Settings & Integrations > Email Notifications. Add or edit the customer notification, without replacing your admin notification.

- Send To: **Select a Field**, then **Email Address** (`email`).
- Subject: **We've received your message | Off Label Research**.
- Paste all of `emails/contact-autoresponder.html` into the email body's **Text/HTML** editor. A plain-text copy of the HTML is supplied as `emails/contact-autoresponder.txt`.
- Use **Send Email as Raw HTML Format** if available to keep Fluent Forms from adding another visual email template.
- Keep a valid, authenticated site sender and monitored Reply-To address; do not use the visitor's address as From.
- Enable and save the notification.

[Official Fluent Forms email setup](https://fluentforms.com/docs/how-to-setup-admin-user-email-notifications/)

## On-page success confirmation

Open Form 3 > Settings & Integrations > Confirmation Settings. Select **Same Page**, paste `messages/contact-success.html` into **Message to show > Text**, choose **Hide Form**, and save. A plain-text copy is supplied as `messages/contact-success.txt`.

The success message confirms receipt of the submission, not email delivery. It is separate from the autoresponder.

[Official Fluent Forms confirmation setup](https://fluentforms.com/docs/setup-form-submission-confirmation-message-in-fluent-forms/)

## Verification

Saved field structure verified in the real Form 3 preview. Local browser layout checks used its field structure with the repository's shared stylesheet at 320, 390, 511, 768, and 1440 pixels. Email and success layouts checked at 320 and 600 pixels. No horizontal overflow. Email-client compatibility and actual delivery are not yet verified.

After publishing the page and adding the templates, send one message using your own email address. Confirm the entry appears, the customer email arrives, and the on-page success message is displayed. This is the remaining end-to-end check.
