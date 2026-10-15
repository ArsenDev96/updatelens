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
