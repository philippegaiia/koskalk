# Future Public Community and Collaboration

Last updated: 2026-07-14

Status: deferred beyond the MVP; product direction only, not an implementation commitment.

## Opportunity

Koskalk may eventually connect hobbyist formulators, small professional makers, and larger professional teams in one community. Hobbyists often develop valuable ideas, while professionals may offer experience, validation, and collaboration opportunities.

The community could become an important differentiator and growth loop, but it must not weaken the confidentiality expected from a professional formulation workspace.

## Product principle

Privacy belongs to each formula, not to a fixed type of user.

A professional may keep most formulas confidential and publish selected work. A hobbyist may publish some formulas while keeping others private. The product should therefore avoid rigid professional-versus-hobbyist account classes.

Public publishing must be a deliberate publication of a selected snapshot. It must never be a visibility switch on the live working formula.

## Proposed separation

### Private workspace

Every working formula is private by default. The private workspace may contain:

- live formulas and complete version history
- ingredient costs, prices, margins, and suppliers
- production batches and lot information
- internal notes and attachments
- regulatory, workspace, and company information

Nothing in this space becomes public automatically.

### Public community

A user may explicitly publish an immutable snapshot of a selected formula version. Before publication, the interface must preview exactly what will become public.

A publication would normally exclude:

- costs, prices, and margins
- suppliers and internal ingredient references
- production batches and lot numbers
- private notes and attachments
- workspace members and company information
- unpublished version history

Possible community capabilities include viewing, commenting, following, saving, duplicating or remixing where allowed, attribution, remix lineage, and collaboration requests.

Popularity must not imply that a formula is safe, compliant, professionally reviewed, or validated by Koskalk. Community reputation, professional identity, and any future verification status must remain distinct concepts.

### Isolated collaboration

Accepting a collaboration request should not expose the original formula or the owner's workspace. Collaboration should begin with a separate shared copy or project containing only the explicitly selected material.

The owner may later incorporate accepted changes into a private working formula. Access must remain scoped to the collaboration project and its invited participants.

## Authorship, ownership, and provenance

These concepts must remain separate:

- **owner**: the user or workspace that currently controls the formula
- **creator**: the user who originally created that formula record
- **publisher**: the user who makes a selected snapshot public
- **source**: the publication or formula version from which a duplicate originated

Duplicating a public formula creates a new private formula owned and created by the duplicating user. Provenance should preserve attribution to the source publication without granting access to the source owner's private workspace.

Public attribution may need a display-name snapshot or pseudonym so it can survive account renames or deletion according to the eventual privacy policy.

## Security boundaries

- Dashboard UUIDs are route identifiers, not sharing credentials.
- Private sharing, if introduced, must use separate revocable and optionally expiring share grants or hashed random tokens.
- Public publication and confidential link sharing are different features and must use different authorization models.
- Private media must not be exposed through permanent public storage URLs.
- Publishing must require an explicit confirmation and field-level preview.
- Unpublishing can remove a Koskalk publication, but cannot recall information that other people have already seen or duplicated; the interface must communicate this clearly.
- Any future collaboration permission must be enforced server-side for every view, edit, export, attachment, and duplication action.

## Possible future data model

Do not provision these tables during the MVP. Their exact shape depends on product validation, moderation, licensing, attribution, and collaboration decisions.

Likely concepts include:

- `recipe_publications` for immutable public snapshots and publication state
- publication provenance such as `source_publication_id`
- `recipe_share_links` or access grants for confidential sharing
- isolated collaboration projects, members, and permissions
- publication comments, saves, reports, and moderation records

Published versions must either be copied into an immutable publication snapshot or protected from normal version pruning.

## Questions to validate before implementation

- Is the public library readable without an account for discovery and SEO?
- Which fields may authors include or exclude from a publication?
- What duplication, attribution, redistribution, and commercial-use choices are offered?
- How are unsafe, misleading, copied, or infringing publications moderated?
- Can authors publish under a personal name, business identity, or pseudonym?
- What exactly can collaborators edit, export, or invite others to access?
- Does collaboration use a formula fork, a dedicated project, or both?
- What happens to publications and attribution after account deletion?

## MVP decision

The MVP remains a secure private professional formulation application. Public community, public formula publishing, confidential share links, and cross-user collaboration are deferred until the core product and its security model are stable and user demand has been validated.
