// Prints the poster frame for a locale: its Dashboard scene, once everything has settled.
// Usage: bun scripts/poster-frame.ts <locale>
import { type Locale } from '../src/copy';
import { posterFrame } from '../src/timeline';

console.log(posterFrame(process.argv[2] as Locale));
