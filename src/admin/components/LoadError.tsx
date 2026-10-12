import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';

import type { ApiError } from '../api/errors';
import { reportTooLargeText } from '../utils/labels';

interface LoadErrorProps {
	error: ApiError;
	/** Message for a generic failure. */
	message: string;
	/** Message when access is denied (default: about reports). */
	forbiddenMessage?: string;
	onRetry: () => void;
	/** Extra action, e.g. a link back. */
	children?: ReactNode;
}

/**
 * Failed request. Shows fixed text per error kind, never server messages.
 *
 * @param props Props.
 */
export function LoadError( {
	error,
	message,
	forbiddenMessage,
	onRetry,
	children,
}: LoadErrorProps ) {
	let text = message;
	if ( error.kind === 'forbidden' ) {
		text =
			forbiddenMessage ??
			__(
				"You don't have permission to view UpdateLens reports.",
				'updatelens'
			);
	} else if ( error.kind === 'not_found' ) {
		text = __( 'This analysis no longer exists.', 'updatelens' );
	} else if ( error.kind === 'too_large' ) {
		text = reportTooLargeText();
	}

	return (
		<div
			role="alert"
			className="space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm"
		>
			<p className="font-medium">{ text }</p>
			<div className="flex flex-wrap items-center gap-3">
				{ error.kind === 'failed' && (
					<Button variant="outline" size="sm" onClick={ onRetry }>
						{ __( 'Retry', 'updatelens' ) }
					</Button>
				) }
				{ children }
			</div>
		</div>
	);
}
