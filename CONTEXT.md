# Koskalk

Koskalk is a professional formulation and production domain for soap and cosmetics. It connects material knowledge, formula design, regulatory context, and repeatable manufacturing without treating them as interchangeable concerns.

## Products and formulation

**Product**:
The complete saved item, including its formula, presentation, label, packaging, and production records.
_Avoid_: Recipe

**Formula**:
The phase-aware quantitative composition of a product.
_Avoid_: Recipe composition

**Current formula**:
The editable working state of a formula, which may differ from the latest stable save.
_Avoid_: Draft version, current recipe version

**Saved formula**:
The latest stable formula snapshot used for the Product page, Formula Sheet, print, and export. It stabilizes the formula composition, not linked ingredient or regulatory reference data.
_Avoid_: Published version, recipe version

**Saved history**:
Older automatic formula snapshots retained for recovery.
_Avoid_: Backups, recovery versions

**Formula Sheet**:
The compact working view of a formula for reviewing, scaling, printing, and reuse.

**Manufacturing procedure**:
The ordered instructions for turning a formula into its product.
_Avoid_: Recipe instructions

**Phase**:
A named, ordered part of a formula that groups ingredients by their role or point of addition.

**Reaction core**:
The soap-specific part of a formula in which saponifiable materials, alkali, and water determine the saponification calculation.

**Soapmaking alkali**:
A Koskalk-curated canonical sodium hydroxide or potassium hydroxide material used by the soap calculation. Workspaces set formula purity and costing, but do not author the material identity.

**Post-reaction additions**:
Ingredients added outside the soap reaction core, such as additives, aromatic materials, and colourants.

**Product family**:
A broad formulation class, such as soap or cosmetic, that determines the formula's calculation basis and applicable behavior.

## Ingredients and soap chemistry

**Ingredient**:
A material that can be selected as part of a formula and carries the identity, composition, and technical data needed to use it correctly.

**Platform ingredient**:
An ingredient curated and kept current by Koskalk for use across workspaces.
_Avoid_: Global ingredient

**Private ingredient**:
An ingredient whose current facts are authored and maintained by a user or workspace rather than by Koskalk.
_Avoid_: Platform ingredient

**Ingredient change review**:
A recheck of a formula's INCI and regulatory guidance after the current data of any linked ingredient changes.
_Avoid_: Catalog review

**Saponifiable ingredient**:
An ingredient whose trusted chemistry allows it to drive the soap saponification calculation.
_Avoid_: Soap oil

**SAP profile**:
The saponification values for an ingredient, with KOH SAP as the canonical source value and NaOH SAP derived from it.

**Fatty-acid profile**:
The normalized fatty-acid composition of an ingredient used to derive soap behavior, technical references, and formulation guidance.

**Aromatic material**:
An ingredient whose composition may require allergen declaration and IFRA evaluation, such as an essential oil or fragrance material.

## Regulatory context

**Regulatory regime**:
The selected market rule set that determines which allergen declarations and substance restrictions apply to a formula.
_Avoid_: Regulation

**Exposure mode**:
The formula's use context, such as rinse-off or leave-on, used to select applicable declaration thresholds and restrictions.

**Allergen**:
A declarable reference substance used to evaluate ingredient composition for labeling under a regulatory regime.

**Substance**:
A neutrally tracked constituent, whole ingredient, or group whose regulatory treatment is determined by the selected regime.
_Avoid_: Restricted substance

**IFRA product category**:
The product-use and exposure context used to select the applicable limit from an IFRA certificate.
_Avoid_: Ingredient category

**IFRA certificate**:
A source document for an aromatic ingredient that states maximum use levels by IFRA product category.

**Compliance guidance**:
A point-in-time, non-authoritative evaluation using the current formula, linked ingredient data, regulatory regime, and IFRA context. It supports professional review but is not a regulatory approval or toxicological assessment.
_Avoid_: Compliance assessment, compliance certification, compliance approval

**Regulatory dossier**:
The formal safety and compliance records maintained by the user with their qualified regulatory professional outside Koskalk.
_Avoid_: Koskalk compliance report

**Toxicologist**:
The qualified professional responsible for the authoritative safety and regulatory evaluation of the cosmetic product.

## Ownership

**Workspace**:
The private collaboration and ownership boundary within which members manage formulas, ingredients, production, purchasing, and inventory.
_Avoid_: Company, account, tenant

**User**:
An authenticated person who owns or belongs to one or more workspaces.
_Avoid_: Account

**Workspace member**:
A user granted a role within a workspace.

**Platform data**:
Koskalk-curated reference data shared across workspaces, including catalog and regulatory knowledge.

**Workspace data**:
Private operational or authored data controlled by a workspace.
