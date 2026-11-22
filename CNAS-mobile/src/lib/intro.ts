import { createContext, useContext } from 'react';

/** False while the intro (components/intro.tsx) still covers the screens. Welcome waits for it to play its entrance. */
export const IntroDoneContext = createContext(true);

export function useIntroDone() {
  return useContext(IntroDoneContext);
}
