import type { Tone } from './labels';

/**
 * Restrained colors per tone. Meaning is always also carried by text.
 */
export const TONE_BADGE: Record< Tone, string > = {
	neutral: 'border-slate-200 bg-slate-50 text-slate-700',
	positive: 'border-emerald-200 bg-emerald-50 text-emerald-800',
	progress: 'border-sky-200 bg-sky-50 text-sky-800',
	caution: 'border-amber-200 bg-amber-50 text-amber-900',
	negative: 'border-rose-200 bg-rose-50 text-rose-800',
};

/** Left accent of the compact report status strip; the surface stays neutral. */
export const TONE_STRIP: Record< Tone, string > = {
	neutral: 'border-l-slate-400',
	positive: 'border-l-emerald-500',
	progress: 'border-l-sky-500',
	caution: 'border-l-amber-500',
	negative: 'border-l-rose-500',
};
