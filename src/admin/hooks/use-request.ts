import { useCallback, useEffect, useState } from 'react';

import { ApiError, toApiError } from '../api/errors';

export type RequestState< T > =
	| { status: 'loading' }
	| { status: 'error'; error: ApiError }
	| { status: 'ready'; data: T };

type Settled< T > = Exclude< RequestState< T >, { status: 'loading' } >;

/**
 * Run `load` whenever it changes (memoize it with useCallback) and expose its
 * state. `retry` runs it again. Responses of outdated requests are ignored.
 *
 * @param load Loader.
 */
export function useRequest< T >(
	load: () => Promise< T >
): [ RequestState< T >, () => void ] {
	const [ attempt, setAttempt ] = useState( 0 );
	const [ result, setResult ] = useState< {
		load: () => Promise< T >;
		attempt: number;
		state: Settled< T >;
	} | null >( null );

	useEffect( () => {
		let active = true;
		load().then(
			( data ) => {
				if ( active ) {
					setResult( {
						load,
						attempt,
						state: { status: 'ready', data },
					} );
				}
			},
			( error: unknown ) => {
				if ( active ) {
					setResult( {
						load,
						attempt,
						state: { status: 'error', error: toApiError( error ) },
					} );
				}
			}
		);
		return () => {
			active = false;
		};
	}, [ load, attempt ] );

	const retry = useCallback( () => setAttempt( ( n ) => n + 1 ), [] );
	const current =
		result && result.load === load && result.attempt === attempt
			? result.state
			: { status: 'loading' as const };

	return [ current, retry ];
}
