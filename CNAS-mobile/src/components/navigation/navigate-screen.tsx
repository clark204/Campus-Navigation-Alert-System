import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useMemo, useRef, useState, useSyncExternalStore } from 'react';
import { ActivityIndicator, Keyboard, Pressable, StyleSheet, Text, View, type LayoutChangeEvent } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useCampusMap, type CampusMap } from '@/lib/campus-map';
import { floorParts, GPS_MAX_ACCURACY, prepareNavigation, type Fix, type Navigation, type Place } from '@/lib/navigation/engine';
import { watchLocation, type LocationStatus } from '@/lib/navigation/location';
import { placeDestination, placeDetail, titleCase, type Destination, type SearchResult } from '@/lib/navigation/places';
import { checkHere, hereStart, NavigationSession, placeStart, planRoute, type Plan, type Start } from '@/lib/navigation/session';
import { voice } from '@/lib/navigation/voice';
import { placesStore, recordRecentPlace, toggleSavedPlace } from '@/lib/portal-data';
import { isAdmin } from '@/lib/roles';
import { useSession } from '@/lib/session';
import { colors, fonts, space, type } from '@/theme';
import { Icon } from '../icon';
import { useMapFrame, ZoomableMap, type CameraTarget } from '../zoomable-map';
import { DestinationPin, RouteLayer, SAFE_COLOR, StartDot, UserDot } from './map-layers';
import { ArrivedSheet, DebugPanel, InstructionBanner, MapButton, NavBottom, NavNotes } from './nav-panels';
import { QrGlyph, RouteCard, SearchField, SearchResults, StartChip, SuggestionCard } from './plan-panels';
import { QrScanner } from './qr-scanner';

// Zoom while following the dot. The web's FOLLOW_ZOOM (19.3) is about 8 px per meter on campus; a bit less here,
// since the drawn map is scaled up rather than redrawn.
const FOLLOW_PIXELS_PER_METER = 7;
// Where the campus first fits: below the search field and chip, above the bottom card.
const PLAN_TOP = 128;
const PLAN_BOTTOM = 230;
// The web's default start (?from= missing): the Entrance Gate.
const DEFAULT_START = 'p1';

type StartChoice = { kind: 'here' } | { kind: 'place'; place: Place };
type Searching = 'to' | 'from' | null;
type Planned = { plan: Plan | null; error: string | null; note: string | null; key: number };

const noopSubscribe = () => () => {};

/**
 * Walking directions on campus, like /navigate on the website: pick a destination by the names students know, see
 * the route, then follow the moving dot with voice directions. Start is My location (when GPS is precise enough and
 * on campus), a picked place, or a CNAS QR code. Route params: ?to=<place id>&from=<place id> (the QR links).
 */
export function NavigateScreen() {
  const { map, loading, retry } = useCampusMap();
  const navigation = useMemo(() => (map ? prepareNavigation(map) : null), [map]);
  const [size, setSize] = useState<{ width: number; height: number } | null>(null);

  const onLayout = (event: LayoutChangeEvent) => {
    const { width, height } = event.nativeEvent.layout;
    setSize((current) => (current?.width === width && current?.height === height ? current : { width, height }));
  };

  return (
    <View style={styles.screen} onLayout={onLayout}>
      {map && navigation && size ? (
        <Navigator map={map} navigation={navigation} width={size.width} height={size.height} />
      ) : (
        <View style={styles.empty}>
          {loading ? (
            <ActivityIndicator color={colors.primary} size="large" />
          ) : (
            <>
              <Text style={[type.body, styles.center]}>The map hasn’t loaded yet. Check your internet connection.</Text>
              <Pressable onPress={retry} accessibilityRole="button" style={styles.retry}>
                <Text style={styles.retryText}>Try again</Text>
              </Pressable>
            </>
          )}
          <BackButton />
        </View>
      )}
    </View>
  );
}

function BackButton() {
  const insets = useSafeAreaInsets();
  return (
    <Pressable
      onPress={() => (router.canGoBack() ? router.back() : router.replace('/'))}
      accessibilityRole="button"
      accessibilityLabel="Back"
      style={({ pressed }) => [styles.back, { top: insets.top + space.sm }, pressed && styles.pressed]}>
      <Icon name="back" size={24} color={colors.primary} strokeWidth={2.4} />
    </Pressable>
  );
}

type NavigatorProps = { map: CampusMap; navigation: Navigation; width: number; height: number };

function Navigator({ map, navigation, width, height }: NavigatorProps) {
  const insets = useSafeAreaInsets();
  const params = useLocalSearchParams<{ from?: string; to?: string }>();
  const frame = useMapFrame(map, width, height, insets.top + PLAN_TOP, PLAN_BOTTOM);
  const { user } = useSession();
  // Saved and recent places are the portal's (students, faculty and staff), not the admin's or a guest's.
  const canSave = user !== null && !isAdmin(user);
  const places = useSyncExternalStore(placesStore.subscribe, placesStore.get);
  const savedCodes = useMemo(() => new Set((places.data?.saved ?? []).map((place) => place.code)), [places.data]);
  const [saveMessage, setSaveMessage] = useState<string | null>(null);
  useEffect(() => {
    if (canSave) placesStore.load();
  }, [canSave]);

  // ---------- Planning. A plan is made when the start or destination is chosen, and once more when My location
  // becomes usable while the route starts at the gate. Not on every fix: a plan from My location splits a walkway
  // in the graph, like a reroute.
  const [startChoice, setStartChoice] = useState<StartChoice>(() => {
    const from = params.from ? navigation.placeById.get(params.from) : undefined;
    return from ? { kind: 'place', place: from } : { kind: 'here' };
  });
  const [destination, setDestination] = useState<Destination | null>(() => {
    const to = params.to ? navigation.placeById.get(params.to) : undefined;
    return to ? placeDestination(to) : null;
  });
  const [searching, setSearching] = useState<Searching>(null);
  const [query, setQuery] = useState('');
  const [planned, setPlanned] = useState<Planned | null>(null);
  const [scanning, setScanning] = useState(false);
  const [session, setSession] = useState<NavigationSession | null>(null);
  const view = useSyncExternalStore(session ? session.subscribe : noopSubscribe, () => session?.view ?? null);

  // ---------- Location, for the whole screen: "My location" before Start, the dot after.
  const [fix, setFix] = useState<Fix | null>(null);
  const [location, setLocation] = useState<LocationStatus>('waiting');
  const sessionRef = useRef<NavigationSession | null>(null);
  // What the location callback needs (it is set up once): the latest fix and status, and whether the plan is
  // waiting for My location (it starts at the gate for now).
  const latest = useRef<{ fix: Fix | null; location: LocationStatus; waitingForHere: boolean; replan: () => void }>({
    fix: null,
    location: 'waiting',
    waitingForHere: false,
    replan: () => {},
  });
  useEffect(() => {
    let stop = () => {};
    let cancelled = false;
    watchLocation(
      (next) => {
        latest.current.fix = next;
        setFix(next);
        sessionRef.current?.applyFix(next);
        if (latest.current.waitingForHere && !sessionRef.current && checkHere(navigation, next).ok) latest.current.replan();
      },
      () => {},
    ).then((result) => {
      if (cancelled) return result.stop();
      stop = result.stop;
      latest.current.location = result.status;
      setLocation(result.status);
      sessionRef.current?.setLocation(result.status);
    });
    return () => {
      cancelled = true;
      stop();
    };
  }, [navigation]);
  const here = checkHere(navigation, fix);

  // My location when it is precise enough and on campus, else the Entrance Gate with a note why.
  const fallback = navigation.placeById.get(DEFAULT_START) ?? navigation.places.find((p) => p.kind === 'gate') ?? null;
  const resolveStart = (choice: StartChoice): { start: Start | null; note: string | null; waitingForHere: boolean } => {
    if (choice.kind === 'place') return { start: placeStart(choice.place), note: null, waitingForHere: false };
    const check = checkHere(navigation, latest.current.fix);
    if (check.ok) return { start: hereStart(navigation, check.coord), note: null, waitingForHere: false };
    const status = latest.current.location;
    const canWait = status !== 'denied' && status !== 'off';
    if (!fallback) return { start: null, note: null, waitingForHere: canWait };
    const gate = fallback.name;
    const why = !canWait
      ? `Location is off for CNAS, so the route starts at ${gate}.`
      : check.reason === 'imprecise' && check.accuracy !== null
        ? `Your location is only accurate to about ${Math.round(check.accuracy)} m here (it needs ${GPS_MAX_ACCURACY} m), so the route starts at ${gate} for now.`
        : check.reason === 'far'
          ? `You don’t seem to be on campus, so the route starts at ${gate}.`
          : `Finding your location… For now the route starts at ${gate}.`;
    return { start: placeStart(fallback), note: `${why} Tap “From” to change it.`, waitingForHere: canWait && check.reason !== 'far' };
  };

  const planKey = useRef(0);
  const makePlan = (choice: StartChoice, to: Destination | null) => {
    latest.current.waitingForHere = false;
    if (!to) {
      setPlanned(null);
      return;
    }
    const { start, note, waitingForHere } = resolveStart(choice);
    latest.current.waitingForHere = waitingForHere;
    planKey.current++;
    if (!start) {
      setPlanned({ plan: null, error: 'No walkway was found near you.', note: null, key: planKey.current });
      return;
    }
    const result = planRoute(navigation, start, to);
    setPlanned('plan' in result ? { plan: result.plan, error: null, note, key: planKey.current } : { plan: null, error: result.error, note, key: planKey.current });
  };
  const choose = (choice: StartChoice, to: Destination | null) => {
    setStartChoice(choice);
    setDestination(to);
    makePlan(choice, to);
  };
  // The location callback (and the first plan below) plan with what is chosen now.
  useEffect(() => {
    latest.current.replan = () => makePlan(startChoice, destination);
  });
  // A destination from ?to= is planned once the screen is up.
  const firstPlan = useRef(false);
  useEffect(() => {
    if (firstPlan.current) return;
    firstPlan.current = true;
    if (destination) latest.current.replan();
  }, [destination]);

  const pick = (result: SearchResult) => {
    Keyboard.dismiss();
    if (searching === 'from') {
      const place = result.destination.place;
      if (place) choose({ kind: 'place', place }, destination);
    } else {
      choose(startChoice, result.destination);
    }
    setSearching(null);
    setQuery('');
  };

  // ---------- Navigating
  const [following, setFollowing] = useState(true);
  const [recenter, setRecenter] = useState(0);

  const begin = () => {
    if (!planned?.plan || !destination) return;
    let plan = planned.plan;
    // From My location, start from where the person is now, not where they were when the route was drawn.
    if (plan.from.kind === 'here') {
      const check = checkHere(navigation, latest.current.fix);
      const start = check.ok ? hereStart(navigation, check.coord) : null;
      const fresh = start ? planRoute(navigation, start, destination) : null;
      if (fresh && 'plan' in fresh) plan = fresh.plan;
    }
    latest.current.waitingForHere = false;
    // Start puts a room at the top of Recent (best effort, never in the way).
    if (canSave && destination.place?.kind === 'room') recordRecentPlace(destination.place.id);
    const next = new NavigationSession(navigation, plan, location);
    sessionRef.current = next;
    setSession(next);
    setFollowing(true);
  };

  const closeSession = () => {
    sessionRef.current?.stop();
    sessionRef.current = null;
    setSession(null);
  };
  // End: back to the route preview, from where the person is now (main.js backToPlan).
  const end = () => {
    session?.finish(false);
    closeSession();
    makePlan(startChoice, destination);
  };
  const done = () => {
    closeSession();
    choose(startChoice, null);
  };
  useEffect(() => () => sessionRef.current?.stop(), []);

  const muted = useSyncExternalStore(
    (listener) => voice.subscribe(listener),
    () => voice.muted,
  );

  const scan = (result: { from: Place | null; to: Place | null }) => {
    setScanning(false);
    const at = result.from ?? result.to;
    if (session && view && !view.ended) {
      if (at) session.scanAt(at);
      return;
    }
    // A code names where it is posted (?from=); one that only names a destination (?to=) is a trip to it.
    const from: StartChoice = result.from ? { kind: 'place', place: result.from } : startChoice;
    choose(from, result.to ? placeDestination(result.to) : destination);
  };

  // ---------- Layout and camera
  const [topHeight, setTopHeight] = useState(insets.top + PLAN_TOP);
  const [bottomHeight, setBottomHeight] = useState(PLAN_BOTTOM);
  const measureTop = (event: LayoutChangeEvent) => setTopHeight(event.nativeEvent.layout.height);
  const measureBottom = (event: LayoutChangeEvent) => setBottomHeight(event.nativeEvent.layout.height);

  if (!frame) return null;
  const walking = view !== null && !view.ended;
  const plan = walking ? view.plan : planned?.plan ?? null;

  let camera: CameraTarget | null = null;
  if (walking) {
    if (view.hold) camera = { key: `hold-${view.lineKey}`, fit: view.hold.coords };
    else if (following) camera = { key: `follow-${recenter}-${view.dot.join(',')}`, center: view.dot, pixelsPerMeter: FOLLOW_PIXELS_PER_METER, duration: 450 };
  } else if (planned?.plan) {
    camera = { key: `plan-${planned.key}`, fit: planned.plan.route.line.geometry.coordinates };
  }

  const routeColor = plan?.to.kind === 'evacuation' ? SAFE_COLOR : undefined;
  let overlay = null;
  if (walking) {
    overlay = <RouteLayer map={map} frame={frame} parts={[view.line]} drawKey={`walk-${view.lineKey}`} color={routeColor} floor={view.floor} />;
  } else if (planned?.plan) {
    const parts = floorParts(planned.plan.route);
    const destinationFloor = planned.plan.route.nodes.at(-1)?.floor ?? 1;
    overlay = <RouteLayer map={map} frame={frame} parts={parts.ground} upper={parts.upper} drawKey={`plan-${planned.key}`} color={routeColor} floor={destinationFloor} />;
  }

  const floor = walking ? view.floor : (planned?.plan?.route.nodes.at(-1)?.floor ?? 1);
  const startLabel = startChoice.kind === 'place' ? startChoice.place.name : planned?.plan?.from.kind === 'place' && destination ? planned.plan.from.name : 'My location';
  const hereDetail = here.ok
    ? `Accurate to about ${Math.round(fix?.coords.accuracy ?? 0)} m`
    : location === 'denied' || location === 'off'
      ? 'Location is off for CNAS'
      : here.reason === 'imprecise'
        ? `Only accurate to about ${Math.round(here.accuracy ?? 0)} m yet`
        : here.reason === 'far'
          ? 'You don’t seem to be on campus'
          : 'Finding your location…';

  // ---------- Saved places (signed in to the portal, a room as destination): the star, like the web's Save.
  const saveRoom = canSave && destination?.place?.kind === 'room' ? destination.place : null;
  const saveButton = saveRoom
    ? {
        saved: savedCodes.has(saveRoom.id),
        message: saveMessage,
        onToggle: () => {
          setSaveMessage(null);
          toggleSavedPlace({ code: saveRoom.id, name: saveRoom.name, location: placeDetail(navigation, saveRoom) }).catch(() => {
            setSaveMessage(savedCodes.has(saveRoom.id) ? 'Couldn’t remove' : 'Couldn’t save');
            setTimeout(() => setSaveMessage(null), 3000);
          });
        },
      }
    : null;

  return (
    <>
      <ZoomableMap
        map={map}
        frame={frame}
        overlay={overlay}
        camera={camera}
        viewport={{ top: topHeight, bottom: bottomHeight }}
        walkwayOpacity={floor > 1 ? 0.3 : 1}
        onUserMove={() => walking && setFollowing(false)}>
        {!walking && plan && <StartDot frame={frame} coord={plan.route.nodes[0].coord} />}
        {!walking && plan && plan.to.showPin !== false && <DestinationPin frame={frame} coord={plan.route.nodes.at(-1)!.coord} dropKey={planned?.key ?? 0} />}
        {walking && view.pin && <DestinationPin frame={frame} coord={view.pin} dropKey={view.pinKey} />}
        {walking ? (
          <UserDot frame={frame} coord={view.dot} heading={view.heading.value} />
        ) : here.ok ? (
          <UserDot frame={frame} coord={here.coord} heading={null} />
        ) : null}
      </ZoomableMap>

      {walking ? (
        <>
          <View style={styles.top} onLayout={measureTop}>
            <InstructionBanner view={view} topInset={insets.top} />
            <NavNotes view={view} />
          </View>
          <View style={[styles.mapButtons, { bottom: bottomHeight + space.md }]}>
            <MapButton label={muted ? 'Unmute voice' : 'Mute voice'} active={muted} onPress={() => voice.setMuted(!muted)}>
              <Icon name={muted ? 'mute' : 'volume'} size={24} color={colors.primary} strokeWidth={2.2} />
            </MapButton>
            <MapButton label="Scan a CNAS QR code where you are" onPress={() => setScanning(true)}>
              <QrGlyph />
            </MapButton>
            {!following && (
              <MapButton
                label="Follow my location again"
                onPress={() => {
                  setFollowing(true);
                  setRecenter((n) => n + 1);
                }}>
                <Icon name="locate" size={24} color={colors.primary} strokeWidth={2.2} />
              </MapButton>
            )}
          </View>
          <View style={styles.bottom} onLayout={measureBottom}>
            <NavBottom view={view} session={session!} bottomInset={insets.bottom} onEnd={end} />
          </View>
          {__DEV__ && (
            <View style={[styles.debugBox, { bottom: bottomHeight + space.sm }]}>
              <DebugPanel view={view} session={session!} />
            </View>
          )}
        </>
      ) : view?.arrived ? (
        <View style={styles.bottom} onLayout={measureBottom}>
          <ArrivedSheet text={view.arrived} bottomInset={insets.bottom} onDone={done} />
        </View>
      ) : (
        <>
          <View style={[styles.top, styles.planTop, { paddingTop: insets.top + space.sm }]} onLayout={measureTop}>
            <View style={styles.searchRow}>
              <Pressable
                onPress={() => {
                  if (searching) {
                    Keyboard.dismiss();
                    setSearching(null);
                    setQuery('');
                  } else if (router.canGoBack()) router.back();
                  else router.replace('/');
                }}
                accessibilityRole="button"
                accessibilityLabel={searching ? 'Close search' : 'Back'}
                style={({ pressed }) => [styles.roundButton, pressed && styles.pressed]}>
                <Icon name="back" size={24} color={colors.primary} strokeWidth={2.4} />
              </Pressable>
              <SearchField
                value={searching ? query : searching === null && destination ? destination.name : ''}
                placeholder={searching === 'from' ? 'Start from…' : 'Where to?'}
                onChangeText={setQuery}
                onFocus={() => {
                  if (!searching) {
                    setSearching('to');
                    setQuery('');
                  }
                }}
                onClear={() => {
                  setQuery('');
                  if (!searching) choose(startChoice, null);
                }}
              />
            </View>
            {!searching && (
              <StartChip
                label={startLabel}
                detail={startChoice.kind === 'here' && here.ok && fix ? `±${Math.round(fix.coords.accuracy)} m` : null}
                onPress={() => {
                  setSearching('from');
                  setQuery('');
                }}
                onScan={() => setScanning(true)}
              />
            )}
            {searching && (
              <SearchResults
                navigation={navigation}
                query={query}
                onPick={pick}
                here={searching === 'from' ? { label: 'My location', detail: hereDetail, available: here.ok } : undefined}
                onPickHere={
                  searching === 'from'
                    ? () => {
                        Keyboard.dismiss();
                        choose({ kind: 'here' }, destination);
                        setSearching(null);
                        setQuery('');
                      }
                    : undefined
                }
              />
            )}
          </View>
          {!searching && (
            <View style={styles.bottom} onLayout={measureBottom}>
              {destination ? (
                <RouteCard
                  title={titleCase(destination.name)}
                  detail={destination.place ? placeDetail(navigation, destination.place) : null}
                  plan={planned?.plan ?? null}
                  error={planned?.error ?? null}
                  note={planned?.note ?? null}
                  bottomInset={insets.bottom}
                  onStart={begin}
                  onClose={() => choose(startChoice, null)}
                  save={saveButton}
                />
              ) : (
                <SuggestionCard
                  navigation={navigation}
                  bottomInset={insets.bottom}
                  onBrowse={(building) => {
                    setSearching('to');
                    setQuery(building);
                  }}
                />
              )}
            </View>
          )}
        </>
      )}

      <QrScanner
        navigation={navigation}
        visible={scanning}
        hint={walking ? 'Scan the code where you are: the dot moves there.' : 'Scan the code where you are to start from there.'}
        onClose={() => setScanning(false)}
        onScan={scan}
      />
    </>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: '#d5e8c3', overflow: 'hidden' },
  empty: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: space.xl, gap: space.md },
  center: { textAlign: 'center' },
  retry: { minHeight: 44, justifyContent: 'center' },
  retryText: { fontFamily: fonts.semibold, fontSize: 17, color: colors.primary, textDecorationLine: 'underline' },
  back: { position: 'absolute', left: space.md, width: 48, height: 48, borderRadius: 24, backgroundColor: colors.surface, alignItems: 'center', justifyContent: 'center' },
  pressed: { opacity: 0.8 },
  top: { position: 'absolute', left: 0, right: 0, top: 0 },
  planTop: { paddingHorizontal: space.md, gap: space.sm },
  searchRow: { flexDirection: 'row', alignItems: 'center', gap: space.sm },
  roundButton: {
    width: 52,
    height: 52,
    borderRadius: 26,
    backgroundColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: '#000',
    shadowOpacity: 0.14,
    shadowRadius: 10,
    shadowOffset: { width: 0, height: 3 },
    elevation: 6,
  },
  bottom: { position: 'absolute', left: 0, right: 0, bottom: 0 },
  mapButtons: { position: 'absolute', right: space.md, gap: space.sm },
  debugBox: { position: 'absolute', left: 0, right: 80 },
});
