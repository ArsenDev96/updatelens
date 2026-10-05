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

export const TONE_NOTICE: Record< Tone, string > = {
	neutral: 'border-slate-200 bg-slate-50',
	positive: 'border-emerald-200 bg-emerald-50/60',
	progress: 'border-sky-200 bg-sky-50/70',
	caution: 'border-amber-200 bg-amber-50/70',
	negative: 'border-rose-200 bg-rose-50/70',
};
