import { loadFont as loadMono } from '@remotion/google-fonts/GoogleSansCode';
import { loadFont as loadSans } from '@remotion/google-fonts/IBMPlexSans';
import { loadFont as loadSerif } from '@remotion/google-fonts/IBMPlexSerif';

const sans = loadSans('normal', {
    weights: ['400', '500', '600', '700'],
    subsets: ['latin'],
});
const serif = loadSerif('italic', {
    weights: ['500', '600'],
    subsets: ['latin'],
});
const mono = loadMono('normal', {
    weights: ['400', '500', '600'],
    subsets: ['latin'],
});

export const fonts = {
    sans: sans.fontFamily,
    serif: serif.fontFamily,
    mono: mono.fontFamily,
};
