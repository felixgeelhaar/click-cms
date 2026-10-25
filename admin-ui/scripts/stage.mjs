import { cp, mkdir, rm } from 'node:fs/promises';

// PHP serves public/admin; keep dist too for Docker and static previews.
const source = new URL('../dist/', import.meta.url);
const destination = new URL('../../public/admin/', import.meta.url);
await mkdir(new URL('../../public/', import.meta.url), { recursive: true });
await rm(destination, { recursive: true, force: true });
await cp(source, destination, { recursive: true });
console.log('Admin UI staged in public/admin');
