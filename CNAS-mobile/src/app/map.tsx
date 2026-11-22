import { CampusMapScreen } from '@/components/campus-map-screen';

/** The campus map for guests (Welcome's mini-map), with a Back button. Signed in, it is a tab instead. */
export default function GuestMap() {
  return <CampusMapScreen withBack />;
}
