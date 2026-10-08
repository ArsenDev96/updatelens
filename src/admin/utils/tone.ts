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

/** Dot of a status badge. */
export const TONE_DOT: Record< Tone, string > = {
	neutral: 'bg-slate-400',
	positive: 'bg-emerald-500',
	progress: 'bg-sky-500',
	caution: 'bg-amber-500',
	negative: 'bg-rose-500',
};

/** Icon color of the overview's status line. */
export const TONE_ICON: Record< Tone, string > = {
	neutral: 'text-slate-500',
	positive: 'text-emerald-600',
	progress: 'text-sky-600',
	caution: 'text-amber-600',
	negative: 'text-rose-600',
};
