---
paths:
  - 'app/Models/**'
  - 'app/Models/{ProductArea,ProductCategory,ProductType,IfraProductCategory}.php'
---

# Models

## Declare mass assignment with the #[Fillable] attribute
Allow-list mass-assignable fields with a #[Fillable([...])] attribute above the model class — the app standard (71/72 models). $fillable/$guarded properties remain acceptable, but the attribute form is used consistently.

## Auto-increment primary keys with a UUID public_id route key
Keep auto-increment integer primary keys ($table->id()) and apply the HasPublicId concern so public_id is the route key — the app standard. HasUuids/HasUlids are acceptable for a new aggregate that genuinely needs a UUID PK, but auto-increment + public_id is the default here.

## Define casts in a casts() method; custom logic as CastsAttributes classes
Declare casts in protected function casts(): array — the app standard (67/72 models); the $casts property is acceptable. For custom cast behavior create a dedicated class in app/Casts implementing CastsAttributes and reference it by class name.

## Register model events in booted()
Register model events via a protected static function booted(): void using static::creating/updating/saving/saved/deleting closures — the app standard. Observer classes and #[ObservedBy] are valid Laravel alternatives. Concerns hook events through a static boot<ConcernName>() method.

## Compose models from shared concerns traits
Compose models from the shared concerns traits: HasPublicId for uuid route keys, HasTenantOwnership for workspace ownership, HasMediaAssetUsages for media attachments. Add new cross-cutting model behavior as a concern in app/Models/Concerns.

## Eager-load explicitly per query
Eager-load relationships explicitly per query with ->with(...) (or ->loadMissing(...) for already-loaded relations). A model-level $with default is acceptable when a relation is always needed, but explicit per-query loading is the app standard.

## Curated catalog content localizes from translations JSON
Product taxonomy and IFRA rows keep canonical English columns and locale-specific name/short_name/description values in the translations JSON attribute. User-facing builders must call localizedName(), localizedShortName(), or localizedDescription() and fall back to canonical English.
