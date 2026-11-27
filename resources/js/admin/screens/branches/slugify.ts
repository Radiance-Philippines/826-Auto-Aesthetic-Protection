/** Laravel's Str::slug, for a web address filled in from a new record's name */
export const slugify = (name: string): string =>
    name
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/_+/g, '-')
        .replace(/@/g, '-at-')
        .toLowerCase()
        .replace(/[^-\p{L}\p{N}\s]+/gu, '')
        .replace(/[-\s]+/g, '-')
        .replace(/^-|-$/g, '')
        .slice(0, 80);
