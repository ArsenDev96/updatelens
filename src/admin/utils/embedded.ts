import { createContext, useContext } from 'react';

/**
 * Whether a signal's details are embedded in a report's Configuration
 * changes (inside its source row, whose name and counts are already shown):
 * the summary then keeps only its rules line and note, and list titles are
 * one heading level lower.
 */
const Embedded = createContext( false );

export const EmbeddedProvider = Embedded.Provider;

/** Whether the details are embedded (see EmbeddedProvider). */
export const useEmbedded = () => useContext( Embedded );

/**
 * ID of the element naming the source the embedded details belong to (its
 * row's title), or null. Lists add it to their region's name ("WP-Cron
 * Changed (1)"): several sources can be open at once, and landmarks need
 * distinct names.
 */
const EmbeddedLabel = createContext< string | null >( null );

export const EmbeddedLabelProvider = EmbeddedLabel.Provider;

/** The embedded source's naming element ID (see EmbeddedLabelProvider). */
export const useEmbeddedLabel = () => useContext( EmbeddedLabel );
