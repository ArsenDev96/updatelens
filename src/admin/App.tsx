import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from 'react';

import {
	Card,
	CardContent,
	CardDescription,
	CardHeader,
	CardTitle,
} from '@/components/ui/card';

type Status = {
	version: string;
};

type ConnectionState =
	| { kind: 'loading' }
	| { kind: 'ready'; status: Status }
	| { kind: 'error'; message: string };

function errorMessage( error: unknown ): string {
	// apiFetch rejects with the REST error body ({ code, message, data }).
	if ( error && typeof error === 'object' && 'message' in error ) {
		return String( error.message );
	}
	return __( 'Unknown error.', 'updatelens' );
}

export default function App() {
	const [ connection, setConnection ] = useState< ConnectionState >( {
		kind: 'loading',
	} );

	useEffect( () => {
		let cancelled = false;

		apiFetch< Status >( { path: '/updatelens/v1/status' } )
			.then( ( status ) => {
				if ( ! cancelled ) {
					setConnection( { kind: 'ready', status } );
				}
			} )
			.catch( ( error: unknown ) => {
				if ( ! cancelled ) {
					setConnection( {
						kind: 'error',
						message: errorMessage( error ),
					} );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [] );

	// The page <h1> ("UpdateLens") is rendered server-side by AdminPage::render().
	return (
		<div className="mt-2 max-w-2xl space-y-4">
			<p className="text-base text-muted-foreground">
				{ __(
					'Understand what changes when your WordPress plugins update.',
					'updatelens'
				) }
			</p>
			<Card>
				<CardHeader>
					<CardTitle>
						{ __( 'Prototype foundation ready.', 'updatelens' ) }
					</CardTitle>
					<CardDescription>
						{ __( 'No snapshots are captured yet.', 'updatelens' ) }
					</CardDescription>
				</CardHeader>
				<CardContent className="text-sm">
					<p className="text-muted-foreground" role="status">
						{ connection.kind === 'loading' &&
							__( 'Checking REST API…', 'updatelens' ) }
						{ connection.kind === 'ready' &&
							sprintf(
								/* translators: %s: plugin version number. */
								__(
									'REST API connected (version %s).',
									'updatelens'
								),
								connection.status.version
							) }
						{ connection.kind === 'error' && (
							<span className="text-destructive">
								{ sprintf(
									/* translators: %s: error message. */
									__( 'REST API error: %s', 'updatelens' ),
									connection.message
								) }
							</span>
						) }
					</p>
				</CardContent>
			</Card>
		</div>
	);
}
