// @vitest-environment node
/**
 * The build's contract with WordPress: React and the other WordPress
 * packages come from WordPress's scripts (never bundled), every global the
 * bundle uses has its script handle in Admin\AdminAssets::DEPENDENCIES, and
 * the app uses only APIs that WordPress 6.2 (React 18.2) provides.
 */
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

import {
	NEVER_BUNDLED,
	WP_GLOBALS,
	WP_SCRIPT_HANDLES,
} from '../../vite.config';

const ROOT = join( __dirname, '../..' );

/** Packages bundled into the admin app (listed in THIRD-PARTY-NOTICES.txt). */
const BUNDLED_PACKAGES = [ 'clsx', 'tailwind-merge' ];

/** React 18.2's exports (WordPress 6.2's `react` script). */
const REACT_18_2 = [
	'Children',
	'Component',
	'Fragment',
	'Profiler',
	'PureComponent',
	'StrictMode',
	'Suspense',
	'cloneElement',
	'createContext',
	'createElement',
	'createFactory',
	'createRef',
	'forwardRef',
	'isValidElement',
	'lazy',
	'memo',
	'startTransition',
	'useCallback',
	'useContext',
	'useDebugValue',
	'useDeferredValue',
	'useEffect',
	'useId',
	'useImperativeHandle',
	'useInsertionEffect',
	'useLayoutEffect',
	'useMemo',
	'useReducer',
	'useRef',
	'useState',
	'useSyncExternalStore',
	'useTransition',
	'version',
];

/** `wp.element` functions the app may call (all present in WordPress 6.2). */
const WP_ELEMENT_6_2 = [ 'createRoot' ];

function sourceFiles( dir: string ): string[] {
	return readdirSync( dir, { withFileTypes: true } ).flatMap( ( entry ) => {
		const path = join( dir, entry.name );
		if ( entry.isDirectory() ) {
			return sourceFiles( path );
		}
		return /\.tsx?$/.test( entry.name ) ? [ path ] : [];
	} );
}

const SOURCES = sourceFiles( join( ROOT, 'src' ) ).map( ( path ) => ( {
	path,
	code: readFileSync( path, 'utf8' ),
} ) );

/** Value (not type-only) names imported from a module across src/. */
function importedNames( module: string ): string[] {
	const names = new Set< string >();
	const pattern = new RegExp(
		`import\\s+(type\\s+)?\\{([^}]*)\\}\\s+from\\s+'${ module }'`,
		'g'
	);
	for ( const { code } of SOURCES ) {
		for ( const match of code.matchAll( pattern ) ) {
			if ( match[ 1 ] ) {
				continue;
			}
			for ( const part of match[ 2 ].split( ',' ) ) {
				const name = part.trim();
				if ( name && ! name.startsWith( 'type ' ) ) {
					names.add( name.split( /\s+as\s+/ )[ 0 ] );
				}
			}
		}
	}
	return [ ...names ];
}

/** The built admin app script and its source map, if the app was built. */
function builtMain(): { code: string; sources: string[] } | null {
	const manifestPath = join( ROOT, 'assets/admin/dist/manifest.json' );
	if ( ! existsSync( manifestPath ) ) {
		return null;
	}
	const manifest = JSON.parse( readFileSync( manifestPath, 'utf8' ) );
	const file = join(
		ROOT,
		'assets/admin/dist',
		manifest[ 'src/admin/main.tsx' ].file
	);
	if ( ! existsSync( `${ file }.map` ) ) {
		return null;
	}
	return {
		code: readFileSync( file, 'utf8' ),
		sources: JSON.parse( readFileSync( `${ file }.map`, 'utf8' ) ).sources,
	};
}

describe( 'WordPress-provided scripts', () => {
	it( 'maps React and wp.element to WordPress globals', () => {
		expect( WP_GLOBALS.react ).toBe( 'React' );
		expect( WP_GLOBALS[ '@wordpress/element' ] ).toBe( 'wp.element' );
		expect( WP_GLOBALS ).not.toHaveProperty( 'react/jsx-runtime' );
	} );

	it( 'lists every global’s script handle as a dependency, and nothing else', () => {
		const php = readFileSync(
			join( ROOT, 'includes/Admin/AdminAssets.php' ),
			'utf8'
		);
		const declared = php.match( /const DEPENDENCIES = array\(([^)]*)\);/ );
		expect( declared ).not.toBeNull();
		const dependencies = [
			...( declared?.[ 1 ] ?? '' ).matchAll( /'([^']+)'/g ),
		].map( ( match ) => match[ 1 ] );

		const handles = Object.values( WP_GLOBALS ).map(
			( global ) => WP_SCRIPT_HANDLES[ global ]
		);
		expect( handles ).not.toContain( undefined );
		expect( [ ...dependencies ].sort() ).toEqual( [ ...handles ].sort() );
		// Registered only since WordPress 6.6 (the minimum is 6.2).
		expect( dependencies ).not.toContain( 'react-jsx-runtime' );
	} );

	it( 'imports only WordPress globals and the bundled packages', () => {
		const allowed = [ ...Object.keys( WP_GLOBALS ), ...BUNDLED_PACKAGES ];
		for ( const { path, code } of SOURCES ) {
			for ( const match of code.matchAll(
				/(?:from|import)\s+'([^'./@][^']*|@[^/']+\/[^']+)'/g
			) ) {
				expect(
					allowed,
					`${ path } imports ${ match[ 1 ] }`
				).toContain( match[ 1 ] );
			}
		}
	} );

	it( 'uses only React 18.2 and WordPress 6.2 APIs', () => {
		for ( const name of importedNames( 'react' ) ) {
			expect( REACT_18_2, `react: ${ name }` ).toContain( name );
		}
		for ( const name of importedNames( '@wordpress/element' ) ) {
			expect( WP_ELEMENT_6_2, `@wordpress/element: ${ name }` ).toContain(
				name
			);
		}
		for ( const { path, code } of SOURCES ) {
			for ( const match of code.matchAll( /\bReact\.(\w+)\(/g ) ) {
				expect(
					REACT_18_2,
					`${ path }: React.${ match[ 1 ] }`
				).toContain( match[ 1 ] );
			}
		}
	} );

	it( 'never bundles React, React DOM or Scheduler', () => {
		for ( const id of [
			'/app/node_modules/react/index.js',
			'C:\\app\\node_modules\\react-dom\\client.js',
			'/app/node_modules/react/jsx-runtime.js',
			'/app/node_modules/scheduler/index.js',
		] ) {
			expect( NEVER_BUNDLED.test( id ), id ).toBe( true );
		}
		for ( const id of [
			'/app/node_modules/clsx/dist/clsx.mjs',
			'/app/node_modules/react-hook-form/index.js',
			'/app/src/admin/main.tsx',
		] ) {
			expect( NEVER_BUNDLED.test( id ), id ).toBe( false );
		}
	} );
} );

describe.skipIf( builtMain() === null )( 'the built admin app', () => {
	it( 'contains only app code and the bundled packages', () => {
		const packages = new Set(
			( builtMain()?.sources ?? [] )
				.map( ( source ) =>
					source.match( /node_modules\/((?:@[^/]+\/)?[^/]+)/ )
				)
				.filter( ( match ) => match !== null )
				.map( ( match ) => match[ 1 ] )
		);
		expect( [ ...packages ].sort() ).toEqual( BUNDLED_PACKAGES );
	} );

	it( 'calls only React 18.2 and wp.element APIs of WordPress 6.2', () => {
		const code = builtMain()?.code ?? '';
		for ( const match of code.matchAll( /\bReact\.(\w+)/g ) ) {
			expect( REACT_18_2, `React.${ match[ 1 ] }` ).toContain(
				match[ 1 ]
			);
		}
		for ( const match of code.matchAll( /\bwp\.element\.(\w+)/g ) ) {
			expect( WP_ELEMENT_6_2, `wp.element.${ match[ 1 ] }` ).toContain(
				match[ 1 ]
			);
		}
		expect( code ).not.toMatch( /__SECRET_INTERNALS_DO_NOT_USE/ );
	} );
} );
