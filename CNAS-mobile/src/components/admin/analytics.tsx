import { router } from 'expo-router';
import { useState, type ReactNode } from 'react';
import { Pressable, StyleSheet, Text, View, type LayoutChangeEvent } from 'react-native';
import Svg, { G, Line, Polyline, Rect, Text as SvgText } from 'react-native-svg';

import type { DashboardStats, Role } from '@/lib/admin';
import { colors, fonts, radius, space, type } from '@/theme';

// The admin dashboard's analytics, the same numbers as the website's overview (CNAS-web AdminAnalytics). Chart colors
// match the web: website #A8323F, app #A87A10 (validated together for color vision deficiency and contrast); every
// chart also says its numbers in text, and a tapped day shows its own.

export const WEB = '#A8323F';
export const APP = '#A87A10';

const ROLES: { role: Role; label: string }[] = [
  { role: 'student', label: 'Students' },
  { role: 'faculty', label: 'Faculty' },
  { role: 'staff', label: 'Staff' },
  { role: 'admin', label: 'Admins' },
];

const short = (date: string) => new Date(`${date}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
const weekday = (date: string) => new Date(`${date}T00:00:00`).toLocaleDateString(undefined, { weekday: 'short' });

/** "+3" / "−2" / "Same as before", and whether it went up. */
function change(now: number, before: number) {
  const diff = now - before;
  return { text: diff === 0 ? 'Same as before' : diff > 0 ? `+${diff}` : `−${Math.abs(diff)}`, up: diff > 0, down: diff < 0 };
}

function Tile({ label, value, detail, children, onPress }: { label: string; value: string; detail?: ReactNode; children?: ReactNode; onPress?: () => void }) {
  const body = (
    <>
      <Text style={styles.tileLabel}>{label}</Text>
      <Text style={styles.tileValue}>{value}</Text>
      {detail}
      {children}
    </>
  );
  return onPress ? (
    <Pressable onPress={onPress} accessibilityRole="button" style={({ pressed }) => [styles.tile, pressed && styles.pressed]}>
      {body}
    </Pressable>
  ) : (
    <View style={styles.tile}>{body}</View>
  );
}

/** Four headline tiles, two by two: accounts, sign-ins (7 days, with a sparkline), open concerns, alert reach. */
export function StatTiles({ stats }: { stats: DashboardStats }) {
  const accounts = change(stats.accounts.this_week, stats.accounts.last_week);
  const signIns = change(stats.sign_ins.last_7, stats.sign_ins.previous_7);
  const totals = stats.sign_ins.days.map((d) => d.web + d.app);
  const max = Math.max(1, ...totals);
  const spark = totals.map((v, i) => `${(i / Math.max(totals.length - 1, 1)) * 100},${28 - (v / max) * 26}`).join(' ');
  const reply = stats.concerns.median_reply_hours;

  return (
    <View style={styles.tiles}>
      <Tile
        label="Accounts"
        value={stats.accounts.total.toLocaleString()}
        detail={
          <Text style={[styles.delta, accounts.up && { color: colors.safe }]}>
            {stats.accounts.this_week} this week ({accounts.text} vs last)
          </Text>
        }
      />
      <Tile
        label="Sign-ins, 7 days"
        value={stats.sign_ins.last_7.toLocaleString()}
        detail={<Text style={[styles.delta, signIns.up && { color: colors.safe }, signIns.down && { color: colors.alarm }]}>{signIns.text} vs the week before</Text>}>
        <Svg width="100%" height={28} viewBox="0 0 100 30" preserveAspectRatio="none" accessibilityLabel="Sign-ins per day, last 14 days">
          <Polyline points={spark} fill="none" stroke={WEB} strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
        </Svg>
      </Tile>
      <Tile
        label="Open concerns"
        value={stats.concerns.open.toLocaleString()}
        onPress={() => router.push('/admin/concerns')}
        detail={<Text style={styles.delta}>{reply === null ? `${stats.concerns.last_30} in 30 days` : `First reply ~${reply < 1 ? '<1' : reply} h`}</Text>}
      />
      <Tile
        label="Alert reach"
        value={(stats.reach.phones + stats.reach.browsers).toLocaleString()}
        detail={
          <Text style={styles.delta}>
            {stats.reach.phones} phones, {stats.reach.browsers} browsers
          </Text>
        }
      />
    </View>
  );
}

/**
 * Sign-ins per day for 14 days: stacked columns (website below, app on top) with a 2px gap, clean gridlines, a legend.
 * Tap a day for its numbers.
 */
export function SignInsChart({ days }: { days: DashboardStats['sign_ins']['days'] }) {
  const [width, setWidth] = useState(0);
  const [selected, setSelected] = useState<number>(days.length - 1);
  const onLayout = (event: LayoutChangeEvent) => setWidth(event.nativeEvent.layout.width);

  const totals = days.map((d) => d.web + d.app);
  const max = Math.max(1, ...totals);
  const step = [1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000].find((s) => max / s <= 4) ?? 2000;
  const top = Math.ceil(max / step) * step;
  const lines = Array.from({ length: top / step + 1 }, (_, i) => i * step);
  const height = 170;
  const left = 26;
  const plotW = Math.max(width - left, 1);
  const slot = plotW / days.length;
  const bar = Math.min(18, slot - 6);
  // Room above the top gridline for its value.
  const pad = 8;
  const y = (v: number) => pad + height - (v / top) * height;
  const day = days[selected];

  return (
    <View style={styles.card}>
      <View style={styles.cardHead}>
        <Text style={[type.label, styles.flex]}>Sign-ins, last 14 days</Text>
        <Legend />
      </View>
      {day && (
        <Text style={styles.selected} accessibilityLiveRegion="polite">
          <Text style={styles.selectedDate}>{short(day.date)}: </Text>
          {day.web + day.app} sign-ins ({day.web} website, {day.app} app)
        </Text>
      )}
      <View onLayout={onLayout} style={{ height: height + pad + 22 }}>
        {width > 0 && (
          <Svg width={width} height={height + pad + 22}>
            {lines.map((v) => (
              <Line key={v} x1={left} x2={width} y1={y(v) + 1} y2={y(v) + 1} stroke={colors.border} strokeWidth={1} />
            ))}
            {lines.map((v) => (
              <SvgText key={`t${v}`} x={0} y={y(v) + 4} fontSize={10} fontFamily={fonts.regular} fill={colors.muted}>
                {v}
              </SvgText>
            ))}
            {days.map((d, i) => {
              const x = left + i * slot + (slot - bar) / 2;
              const webH = (d.web / top) * height;
              const appH = (d.app / top) * height;
              const gap = d.web > 0 && d.app > 0 ? 2 : 0;
              return (
                <G key={d.date}>
                  {i === selected && <Rect x={left + i * slot} y={pad} width={slot} height={height + 1} fill="#F3ECEA" />}
                  {d.web > 0 && <Rect x={x} y={pad + height - webH + 1} width={bar} height={webH} fill={WEB} rx={d.app > 0 ? 0 : 3} />}
                  {d.app > 0 && <Rect x={x} y={pad + height - webH - appH - gap + 1} width={bar} height={appH} fill={APP} rx={3} />}
                  {(i - (days.length - 1)) % 2 === 0 && (
                    <SvgText x={x + bar / 2} y={pad + height + 18} fontSize={10} fontFamily={fonts.regular} fill={colors.muted} textAnchor="middle">
                      {weekday(d.date)}
                    </SvgText>
                  )}
                </G>
              );
            })}
          </Svg>
        )}
        {/* Tap targets wider than the columns: the whole day slot. */}
        <View style={[StyleSheet.absoluteFill, { flexDirection: 'row', left }]}>
          {days.map((d, i) => (
            <Pressable
              key={d.date}
              onPress={() => setSelected(i)}
              accessibilityRole="button"
              accessibilityLabel={`${short(d.date)}: ${d.web + d.app} sign-ins, ${d.web} on the website, ${d.app} in the app`}
              style={styles.flex}
            />
          ))}
        </View>
      </View>
    </View>
  );
}

function Legend() {
  return (
    <View style={styles.legend}>
      <View style={[styles.swatch, { backgroundColor: WEB }]} />
      <Text style={type.small}>Website</Text>
      <View style={[styles.swatch, { backgroundColor: APP, marginLeft: space.sm }]} />
      <Text style={type.small}>App</Text>
    </View>
  );
}

/** Ranked bars: one hue, the value at the end. `of` is what 100% means (the largest row by default). */
export function RankedBars({ title, note, rows, of, empty, stacked }: { title: string; note?: string; rows: { label: string; value: number }[]; of?: number; empty: string; stacked?: boolean }) {
  const max = of ?? Math.max(1, ...rows.map((r) => r.value));
  return (
    <View style={styles.card}>
      <View style={styles.cardHead}>
        <Text style={[type.label, styles.flex]}>{title}</Text>
        {note ? <Text style={type.small}>{note}</Text> : null}
      </View>
      {rows.length === 0 || rows.every((r) => r.value === 0) ? (
        <Text style={type.small}>{empty}</Text>
      ) : (
        rows.map((row) => {
          const bar = (
            <View style={styles.track}>
              <View style={[styles.fill, { width: `${Math.round((row.value / Math.max(max, 1)) * 100)}%` }]} />
            </View>
          );
          return stacked ? (
            <View key={row.label} style={styles.stackedRow}>
              <View style={styles.barRow}>
                <Text style={[styles.flex, styles.stackedLabel]}>{row.label}</Text>
                <Text style={styles.barValue}>{row.value.toLocaleString()}</Text>
              </View>
              {bar}
            </View>
          ) : (
            <View key={row.label} style={styles.barRow}>
              <Text style={styles.barLabel} numberOfLines={1}>
                {row.label}
              </Text>
              {bar}
              <Text style={styles.barValue}>{row.value.toLocaleString()}</Text>
            </View>
          );
        })
      )}
    </View>
  );
}

export const roleRows = (stats: DashboardStats) => ROLES.map(({ role, label }) => ({ label, value: stats.accounts.by_role[role] ?? 0 }));

/** The last emergency: how many answered (I'm safe or I need help) out of every account except admins. */
export function LastEmergency({ last }: { last: DashboardStats['alerts']['last'] }) {
  const share = last && last.accounts > 0 ? Math.min(100, Math.round((last.answered / last.accounts) * 100)) : 0;
  return (
    <View style={styles.card}>
      <Text style={type.label}>Last emergency</Text>
      {!last ? (
        <Text style={type.small}>No emergency or drill has been sent yet. Run a drill to see how many answer.</Text>
      ) : (
        <>
          <Text style={type.body}>
            {last.title}
            {last.is_drill ? '  (drill)' : ''}
          </Text>
          <Text style={styles.meterValue}>
            {share}% <Text style={type.small}>answered ({last.answered} of {last.accounts})</Text>
          </Text>
          <View style={styles.meter} accessibilityLabel={`${share}% answered`}>
            <View style={[styles.meterFill, { width: `${share}%` }]} />
          </View>
          {last.need_help > 0 && <Text style={styles.needHelp}>{last.need_help} asked for help</Text>}
        </>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.85 },
  tiles: { flexDirection: 'row', flexWrap: 'wrap', gap: space.sm },
  tile: { width: '48.5%', flexGrow: 1, gap: 2, padding: space.md, borderRadius: radius.md, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  tileLabel: { fontFamily: fonts.semibold, fontSize: 13, color: colors.muted },
  tileValue: { fontFamily: fonts.bold, fontSize: 28, lineHeight: 34, color: colors.text },
  delta: { fontFamily: fonts.medium, fontSize: 13, lineHeight: 18, color: colors.muted },
  card: { gap: space.sm, padding: space.md, borderRadius: radius.md, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  cardHead: { flexDirection: 'row', alignItems: 'center', gap: space.sm },
  legend: { flexDirection: 'row', alignItems: 'center', gap: 4 },
  swatch: { width: 10, height: 10, borderRadius: 2 },
  selected: { fontFamily: fonts.regular, fontSize: 14, color: colors.text },
  selectedDate: { fontFamily: fonts.semibold },
  barRow: { flexDirection: 'row', alignItems: 'center', gap: space.sm, minHeight: 26 },
  stackedRow: { gap: 4, marginBottom: 4 },
  stackedLabel: { fontFamily: fonts.regular, fontSize: 15, color: colors.text },
  barLabel: { width: 112, fontFamily: fonts.regular, fontSize: 15, color: colors.text },
  track: { flex: 1, height: 10, borderRadius: 5, backgroundColor: '#F1ECE6', overflow: 'hidden' },
  fill: { height: 10, borderTopRightRadius: 4, borderBottomRightRadius: 4, backgroundColor: WEB },
  barValue: { minWidth: 30, textAlign: 'right', fontFamily: fonts.semibold, fontSize: 15, color: colors.text },
  meterValue: { fontFamily: fonts.bold, fontSize: 26, color: colors.text },
  meter: { height: 10, borderRadius: 5, backgroundColor: '#F1ECE6', overflow: 'hidden' },
  meterFill: { height: 10, borderRadius: 5, backgroundColor: colors.safe },
  needHelp: { fontFamily: fonts.semibold, fontSize: 14, color: colors.alarm },
});
