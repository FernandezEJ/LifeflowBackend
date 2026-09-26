// ========================================
// ONE-TIME DONATION INTEGRATION
// Preserves existing styles and account code while replacing donation demo data.
// ========================================
const fs = require('fs');
const root = 'C:/Capstone-LifeFlow/lifeflow-frontend/';
const read = name => fs.readFileSync(root + name, 'utf8').replace(/\r\n/g, '\n');
const changes = new Map();
function replace(s,a,b) { if (!s.includes(a)) throw Error('Missing source: '+a.slice(0,60)); return s.replace(a,b); }
changes.set('src/services/donations.ts', `import { apiRequest } from './api';
// ========================================
// DONATION CONTRACT AND DISPLAY LABELS
// The server owns verification states, counts, and achievement labels.
// ========================================
export type DonationStatus = 'pending' | 'completed' | 'rejected';
export type DonationInput = { donation_date: string; location: string; notes?: string | null };
export type DonationRecord = DonationInput & { id: number; status: DonationStatus; submitted_at: string; verified_at: string | null };
export type DonationPage = { data: DonationRecord[]; current_page: number; last_page: number; total: number };
export type DonationSummary = { total_donations: number; achievement: { key: string; label: string } };
export const DONATION_STATUS = { pending: 'Pending verification', completed: 'Completed', rejected: 'Rejected' };
// ========================================
// AUTHENTICATED DONATION ENDPOINTS
// Submission explicitly sends only editable fields, never status or ownership.
// ========================================
export const donationApi = {
  history: (token: string, page = 1, status?: DonationStatus) => apiRequest<DonationPage>('/donation-records?page=' + page + (status ? '&status=' + status : ''), { token }),
  summary: (token: string) => apiRequest<DonationSummary>('/donation-summary', { token }),
  detail: (token: string, id: string) => apiRequest<{ donation_record: DonationRecord }>('/donation-records/' + encodeURIComponent(id), { token }),
  submit: (token: string, data: DonationInput) => apiRequest<{ donation_record: DonationRecord }>('/donation-records', { method: 'POST', token, body: { donation_date: data.donation_date, location: data.location.trim(), notes: data.notes?.trim() || null } }),
};
`);
let auth = read('src/contexts/auth-context.tsx');
auth = "import { donationApi, type DonationInput, type DonationRecord, type DonationPage, type DonationSummary, type DonationStatus } from '@/services/donations';\n" + auth;
auth = replace(auth, '  logout: () => Promise<string | undefined>;', `  donationHistory: (page?: number, status?: DonationStatus) => Promise<DonationPage>;
  donationSummary: () => Promise<DonationSummary>;
  donationDetail: (id: string) => Promise<{ donation_record: DonationRecord }>;
  submitDonation: (data: DonationInput) => Promise<{ donation_record: DonationRecord }>;
  logout: () => Promise<string | undefined>;`);
auth = replace(auth, '  const latestAssessment =', `  // ========================================
  // PRIVATE DONATION REQUESTS
  // Ignores signed-out sessions and clears expired credentials using existing cleanup.
  // ========================================
  const donationRequest = useCallback(async <T,>(operation: (saved: string) => Promise<T>): Promise<T> => {
    const saved = token.current;
    if (!saved) throw new ApiError('Please log in again.', 401);
    try {
      const response = await operation(saved);
      if (saved !== token.current) throw new ApiError('Your session changed. Please try again.', 401);
      return response;
    } catch (error) {
      if (error instanceof ApiError && error.status === 401 && saved === token.current) {
        try { await clearSession(); } catch (cleanupError) { setSessionError(errorMessage(cleanupError)); }
      }
      throw error;
    }
  }, [clearSession]);
  const donationHistory = useCallback((page = 1, status?: DonationStatus) => donationRequest(saved => donationApi.history(saved, page, status)), [donationRequest]);
  const donationSummary = useCallback(() => donationRequest(donationApi.summary), [donationRequest]);
  const donationDetail = useCallback((id: string) => donationRequest(saved => donationApi.detail(saved, id)), [donationRequest]);
  const submitDonation = useCallback((data: DonationInput) => donationRequest(saved => donationApi.submit(saved, data)), [donationRequest]);

  const latestAssessment =`);
auth = replace(auth, '    latestAssessment, submitAssessment,', '    latestAssessment, submitAssessment, donationHistory, donationSummary, donationDetail, submitDonation,');
changes.set('src/contexts/auth-context.tsx',auth);

// ========================================
// HISTORY WITH EXISTING CARD STYLES
// Filters and pagination load server data; no demo rewards or local verification remain.
// ========================================
const activity = read('src/app/(tabs)/activity.tsx');
changes.set('src/app/(tabs)/activity.tsx', `import MaterialIcons from '@expo/vector-icons/MaterialIcons';
import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useRef, useState } from 'react';
import { FlatList, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useAuth } from '@/contexts/auth-context';
import { errorMessage } from '@/services/api';
import { DONATION_STATUS, type DonationPage, type DonationStatus } from '@/services/donations';
${activity.slice(activity.indexOf('const COLORS'),activity.indexOf('type ActivityStatus'))}
// ========================================
// DONATION HISTORY STATE
// Focus/filter changes cancel stale responses; loading blocks duplicate page requests.
// ========================================
export default function ActivityScreen() {
  const router = useRouter();
  const { donationHistory } = useAuth();
  const [status, setStatus] = useState<DonationStatus | undefined>();
  const [page, setPage] = useState<DonationPage | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const generation = useRef(0);
  const lock = useRef(false);
  const [refresh, setRefresh] = useState(0);
  useFocusEffect(useCallback(() => {
    const version = ++generation.current;
    lock.current = true; setLoading(true); setError(''); setPage(null);
    donationHistory(1, status).then(data => { if (generation.current === version) setPage(data); })
      .catch(e => { if (generation.current === version) setError(errorMessage(e)); })
      .finally(() => { if (generation.current === version) { lock.current = false; setLoading(false); } });
    return () => { generation.current++; };
  }, [donationHistory, status, refresh]));
  const more = async () => {
    if (lock.current || !page || page.current_page >= page.last_page) return;
    const version = generation.current;
    lock.current = true; setLoading(true); setError('');
    try { const data = await donationHistory(page.current_page + 1, status);
      if (version === generation.current) setPage({ ...data, data: [...page.data, ...data.data] });
    } catch(e) { if (version === generation.current) setError(errorMessage(e)); }
    finally { if (version === generation.current) { lock.current = false; setLoading(false); } }
  };
  return <SafeAreaView edges={['top']} style={styles.safeArea}>
    <View style={styles.fixedHeader}><View style={styles.header}><Text style={styles.headerTitle}>Activity</Text>
      <Pressable accessibilityLabel="Open notifications" onPress={() => router.push('/notifications')} style={styles.bellButton}><MaterialIcons name="notifications-none" size={25} color={COLORS.text}/></Pressable>
    </View></View>
    <FlatList data={page?.data || []} keyExtractor={item => String(item.id)} contentContainerStyle={styles.listContent}
      ListHeaderComponent={<View style={styles.listHeader}>
        <Text style={styles.subtitle}>Your donation records</Text>
        <Pressable accessibilityRole="button" style={styles.viewButton} onPress={() => router.push({ pathname: '/activity/[id]', params: { id: 'new' } })}><Text style={styles.viewButtonText}>Submit Donation Record</Text></Pressable>
        <ScrollView horizontal contentContainerStyle={styles.filters}>{([undefined, 'pending', 'completed', 'rejected'] as const).map(value => <Pressable key={value || 'all'} onPress={() => setStatus(value)} style={[styles.filterChip, value === status && styles.filterChipSelected]}><Text style={[styles.filterText, value === status && styles.filterTextSelected]}>{value ? DONATION_STATUS[value] : 'All'}</Text></Pressable>)}</ScrollView>
        <Text style={styles.historyTitle}>Donation history {page ? '(' + page.total + ')' : ''}</Text>
        {error ? <Pressable onPress={() => setRefresh(n => n + 1)}><Text accessibilityRole="alert" style={styles.subtitle}>{error} Tap to retry.</Text></Pressable> : null}
      </View>}
      ListEmptyComponent={<Text style={styles.subtitle}>{loading ? 'Loading records…' : error ? '' : 'No donation records yet.'}</Text>}
      renderItem={({item}) => <View style={styles.card}>
        <View style={styles.cardHeader}><Text style={styles.cardTitle}>{item.location}</Text><Text style={styles.statusText}>{DONATION_STATUS[item.status]}</Text></View>
        <Text style={styles.detailText}>{item.donation_date}</Text><Text style={styles.organizer}>{item.notes || 'No notes'}</Text>
        <View style={styles.cardFooter}><Text style={styles.rewardLabel}>{item.status === 'completed' ? 'Counts toward verified total' : 'Not counted toward verified total'}</Text>
          <Pressable style={styles.viewButton} onPress={() => router.push({ pathname: '/activity/[id]', params: { id: String(item.id) } })}><Text style={styles.viewButtonText}>View</Text></Pressable></View>
      </View>}
      ItemSeparatorComponent={() => <View style={styles.cardSeparator}/>}
      ListFooterComponent={page && page.current_page < page.last_page ? <Pressable disabled={loading} onPress={more} style={styles.viewButton}><Text style={styles.viewButtonText}>{loading ? 'Loading…' : 'Load more'}</Text></Pressable> : null}/>
  </SafeAreaView>;
}
${activity.slice(activity.indexOf('const styles ='))}`);

// ========================================
// EXISTING DETAIL ROUTE AND SUBMISSION
// Reuses the existing route for a new-record form or private read-only details.
// ========================================
const detail = read('src/app/activity/[id].tsx');
changes.set('src/app/activity/[id].tsx', `import MaterialIcons from '@expo/vector-icons/MaterialIcons';
import { Stack, useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import { useCallback, useRef, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useAuth } from '@/contexts/auth-context';
import { errorMessage } from '@/services/api';
import { DONATION_STATUS, type DonationRecord } from '@/services/donations';
${detail.slice(detail.indexOf('const COLORS'),detail.indexOf('type ActivityStatus'))}
// ========================================
// DONATION DETAILS AND PENDING SUBMISSION
// The server controls status. No approval, cancellation, or fake proof upload is offered.
// ========================================
export default function ActivityDetailScreen() {
  const router = useRouter();
  const { id } = useLocalSearchParams<{ id: string }>();
  const isNew = id === 'new';
  const { donationDetail, submitDonation } = useAuth();
  const [record, setRecord] = useState<DonationRecord | null>(null);
  const [date, setDate] = useState(''); const [location, setLocation] = useState(''); const [notes, setNotes] = useState('');
  const [loading, setLoading] = useState(false); const [error, setError] = useState('');
  const lock = useRef(false); const [retry, setRetry] = useState(0);
  useFocusEffect(useCallback(() => {
    let active = true; setRecord(null); setError('');
    if (isNew) return;
    setLoading(true);
    donationDetail(id).then(data => { if (active) setRecord(data.donation_record); })
      .catch(e => { if (active) setError(errorMessage(e)); }).finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [id, isNew, donationDetail, retry]));
  const submit = async () => {
    if (lock.current) return;
    lock.current = true; setLoading(true); setError('');
    try { const data = await submitDonation({ donation_date: date, location, notes });
      router.replace({ pathname: '/activity/[id]', params: { id: String(data.donation_record.id) } });
    } catch(e) { setError(errorMessage(e)); }
    finally { lock.current = false; setLoading(false); }
  };
  return <><Stack.Screen options={{headerShown:false}}/><SafeAreaView edges={['top','bottom']} style={styles.safeArea}>
    <View style={styles.header}><Pressable accessibilityLabel="Go back" onPress={() => router.back()} style={styles.backButton}><MaterialIcons name="arrow-back" size={23} color={COLORS.text}/></Pressable><Text style={styles.headerTitle}>{isNew ? 'Submit Donation' : 'Donation Details'}</Text></View>
    <ScrollView contentContainerStyle={styles.scrollContent} keyboardShouldPersistTaps="handled"><View style={styles.content}>
      {error ? <Pressable onPress={() => setRetry(n => n + 1)}><Text accessibilityRole="alert" style={styles.description}>{error}{!isNew ? ' Tap to retry.' : ''}</Text></Pressable> : null}
      {isNew ? <View style={styles.detailsCard}>
        <Text style={styles.sectionTitle}>Donation record</Text>
        <Text style={styles.description}>Submit details of a donation you already made. It will remain pending until verified by an admin.</Text>
        <Text style={styles.detailLabel}>Donation date (YYYY-MM-DD)</Text><TextInput accessibilityLabel="Donation date" editable={!loading} value={date} onChangeText={setDate} placeholder="YYYY-MM-DD" style={styles.detailValue}/>
        <Text style={styles.detailLabel}>Location</Text><TextInput accessibilityLabel="Location" editable={!loading} value={location} onChangeText={setLocation} maxLength={255} style={styles.detailValue}/>
        <Text style={styles.detailLabel}>Notes (optional)</Text><TextInput accessibilityLabel="Notes" editable={!loading} value={notes} onChangeText={setNotes} maxLength={5000} multiline style={styles.detailValue}/>
        <Pressable accessibilityRole="button" accessibilityState={{disabled:loading,busy:loading}} disabled={loading} onPress={submit} style={styles.uploadButton}><Text style={styles.uploadButtonText}>{loading ? 'Submitting…' : 'Submit for verification'}</Text></Pressable>
      </View> : record ? <>
        <View style={styles.titleBlock}><Text style={styles.statusText}>{DONATION_STATUS[record.status]}</Text><Text style={styles.title}>{record.location}</Text></View>
        <View style={styles.detailsCard}><Text style={styles.detailLabel}>Donation date</Text><Text style={styles.detailValue}>{record.donation_date}</Text><Text style={styles.detailLabel}>Submitted</Text><Text style={styles.detailValue}>{new Date(record.submitted_at).toLocaleString()}</Text><Text style={styles.detailLabel}>Verification date</Text><Text style={styles.detailValue}>{record.verified_at ? new Date(record.verified_at).toLocaleString() : 'Not yet verified'}</Text></View>
        <View style={styles.section}><Text style={styles.sectionTitle}>Notes</Text><Text style={styles.description}>{record.notes || 'No notes'}</Text></View>
        <View style={styles.proofCard}><Text style={styles.description}>{record.status === 'completed' ? 'This completed record counts toward your verified donation total.' : record.status === 'pending' ? 'Waiting for admin verification. This record does not count yet.' : 'This record was rejected and does not count toward your total.'}</Text></View>
      </> : loading ? <Text style={styles.description}>Loading record…</Text> : null}
    </View></ScrollView>
  </SafeAreaView></>;
}
${detail.slice(detail.indexOf('const styles ='))}`);

// ========================================
// PROFILE TOTALS AND ACHIEVEMENT
// Keeps both cards and medal styling, replacing streaks and fake counts.
// ========================================
let profile = read('src/app/(tabs)/profile.tsx');
profile = replace(profile,"import { useRouter }", "import { useFocusEffect, useRouter }");
profile = replace(profile,'import { useEffect,', 'import { useCallback, useEffect,');
profile = "import { type DonationSummary } from '@/services/donations';\n"+profile;
const statsStart = profile.indexOf('// ========================================\n// FUTURE DONATION FEATURES');
const statsEnd = profile.indexOf('// ========================================\n// EDITABLE PERSONAL FIELDS');
profile = profile.slice(0,statsStart)+`// ========================================
// VERIFIED MILESTONE DISPLAY
// The server supplies the current achievement; counts unlock milestone illustrations.
// ========================================
const ACHIEVEMENTS: readonly Achievement[] = [
  { title: 'New Donor', requiredDonations: 0, icon: 'favorite', color: '#A86432', background: '#F5E5D8' },
  { title: 'First-Time Donor', requiredDonations: 1, icon: 'workspace-premium', color: '#A86432', background: '#F5E5D8' },
  { title: 'Bronze Donor', requiredDonations: 3, icon: 'workspace-premium', color: '#A86432', background: '#F5E5D8' },
  { title: 'Silver Donor', requiredDonations: 5, icon: 'workspace-premium', color: '#78838D', background: '#E9EDF0' },
  { title: 'Gold Donor', requiredDonations: 10, icon: 'workspace-premium', color: '#B97700', background: '#FFF1D6' },
];

`+profile.slice(statsEnd);
profile = replace(profile,'  const router = useRouter();',`  const router = useRouter();
  // ========================================
  // REFRESH VERIFIED TOTAL ON FOCUS
  // Errors remain visible and never turn into a fabricated zero or achievement.
  // ========================================
  const { donationSummary } = useAuth();
  const [summary, setSummary] = useState<DonationSummary | null>(null);
  const [summaryError, setSummaryError] = useState('');
  useFocusEffect(useCallback(() => {
    let active = true; setSummary(null); setSummaryError('');
    donationSummary().then(data => { if (active) setSummary(data); })
      .catch(e => { if (active) setSummaryError(errorMessage(e)); });
    return () => { active = false; };
  }, [donationSummary]));
  const CURRENT_MEDAL = summary ? ACHIEVEMENTS.find(item => item.title === summary.achievement.label) : null;`);
profile = profile.replaceAll('DEMO_STATS.totalDonations', "summary?.total_donations ?? '—'").replaceAll('DEMO_STATS.streakCount', "summary?.achievement.label ?? '—'").replaceAll('Streak Count','Donor Achievement').replaceAll('name="local-fire-department"','name="workspace-premium"');
profile = replace(profile,'<Text style={styles.statNumber}>{summary?.achievement.label', '<Text style={styles.currentMedalTitle}>{summary?.achievement.label');
profile = replace(profile,'{/* TODO: Donation totals and streaks will come from verified Laravel donation records. */}', '{summaryError ? <Text accessibilityRole="alert" style={styles.donorLabel}>{summaryError}</Text> : null}');
profile = replace(profile,'VERIFIED_DONATIONS >= achievement.requiredDonations','summary !== null && summary.total_donations >= achievement.requiredDonations');
changes.set('src/app/(tabs)/profile.tsx', profile);
for(const [name,content] of changes) fs.writeFileSync(root+name,content);
console.log('Updated '+[...changes.keys()].join(', '));
