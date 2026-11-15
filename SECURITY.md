# Security policy

## Reporting a vulnerability

Email hello@indianopinion.org with a description, the version, and steps to
reproduce. Please do not open a public issue for a security problem.

We acknowledge reports within 3 working days and aim to ship a fix or a
mitigation within 30 days, sooner for anything that exposes a provider key or
lets a user act on a post they cannot edit. We credit reporters in the release
notes unless asked not to.

## Supported versions

Only the latest release receives security fixes.

## Scope notes

- The provider key never reaches the browser, logs or REST responses.
- Candidate text is model output and is rendered only as text.
- Prompt injection inside an article can change suggestions, but nothing is
  applied without an editor's click.
