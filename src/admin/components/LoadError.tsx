import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';

import type { ApiError } from '../api/errors';
import { reportTooLargeText, requestFailureText } from '../utils/labels';

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
 * Failed request. Shows fixed text per error kind, never server messages:
 * what failed, then why (the site's REST API refused, answered with
 * something other than data, or returned an error), and Retry where it may
 * help.
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
	let cause = requestFailureText( error );
	if ( error.kind === 'forbidden' ) {
		text = forbiddenMessage ?? cause ?? message;
		cause = null;
	} else if ( error.kind === 'not_found' ) {
		text = __( 'This analysis no longer exists.', 'updatelens' );
	} else if ( error.kind === 'too_large' ) {
		text = reportTooLargeText();
	}
	const retry = ! [ 'forbidden', 'not_found', 'too_large' ].includes(
		error.kind
	);

	return (
		<div
			role="alert"
			className="space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm"
		>
			<p className="font-medium">{ text }</p>
			{ cause && <p className="text-slate-700">{ cause }</p> }
			<div className="flex flex-wrap items-center gap-3">
				{ retry && (
					<Button variant="outline" size="sm" onClick={ onRetry }>
						{ __( 'Retry', 'updatelens' ) }
					</Button>
				) }
				{ children }
			</div>
		</div>
	);
}
