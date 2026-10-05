import { __, sprintf } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';

interface PaginationProps {
	page: number;
	totalPages: number;
	onChange: ( page: number ) => void;
}

export function Pagination( { page, totalPages, onChange }: PaginationProps ) {
	if ( totalPages < 1 ) {
		return null;
	}

	return (
		<nav
			aria-label={ __( 'Update history pages', 'updatelens' ) }
			className="flex items-center justify-between gap-3 pt-2"
		>
			<Button
				variant="outline"
				size="sm"
				disabled={ page <= 1 }
				onClick={ () => onChange( page - 1 ) }
			>
				<span aria-hidden="true">←</span>
				{ __( 'Previous', 'updatelens' ) }
			</Button>
			<p className="text-sm text-muted-foreground">
				{ sprintf(
					/* translators: 1: current page number, 2: number of pages. */
					__( 'Page %1$d of %2$d', 'updatelens' ),
					page,
					totalPages
				) }
			</p>
			<Button
				variant="outline"
				size="sm"
				disabled={ page >= totalPages }
				onClick={ () => onChange( page + 1 ) }
			>
				{ __( 'Next', 'updatelens' ) }
				<span aria-hidden="true">→</span>
			</Button>
		</nav>
	);
}
