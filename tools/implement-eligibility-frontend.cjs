// ========================================
// SCOPED FRONTEND INTEGRATION
// Updates only the existing assessment/status screens, auth provider, and API contract.
// Assertions stop this patch if the source no longer matches the inspected version.
// ========================================
const fs = require('fs');
const path = require('path');
const root = 'C:/Capstone-LifeFlow/lifeflow-frontend';
const changes = new Map();
function read(name) { return fs.readFileSync(path.join(root, name), 'utf8').replace(/\r\n/g, '\n'); }
function replace(text, from, to) { if (!text.includes(from)) throw new Error('Missing source: ' + from.slice(0, 80)); return text.replace(from, to); }

changes.set('src/services/eligibility.ts', `import { apiRequest } from './api';

// ========================================
// PRE-SCREENING API CONTRACT
// These types describe server results, never frontend medical decisions.
// ========================================
export type EligibilityAnswers = {
  weight: number; sleepHours: number; currentSymptoms: 'YES' | 'NO';
  medication: 'YES' | 'NO'; donatedWithinThreeMonths: 'YES' | 'NO'; feelsWell: 'YES' | 'NO';
};
export type EligibilityResult = 'eligible' | 'temporarily_ineligible' | 'needs_further_screening';
export type EligibilityAssessment = {
  id: number; result: EligibilityResult; reasons: string[];
  answers: EligibilityAnswers; assessed_at: string;
};
export const RESULT_LABELS: Record<EligibilityResult, string> = {
  eligible: 'Eligible at pre-screening',
  temporarily_ineligible: 'Temporarily ineligible at pre-screening',
  needs_further_screening: 'Further screening needed',
};
export const SCREENING_NOTICE = 'Based on your answers only. Final eligibility is confirmed by the blood donation facility.';

// ========================================
// PROTECTED ASSESSMENT REQUESTS
// Sends answers only and displays results saved by Laravel.
// ========================================
export const eligibilityApi = {
  submit: (token: string, answers: EligibilityAnswers) => apiRequest<{ assessment: EligibilityAssessment }>('/eligibility-assessments', { method: 'POST', token, body: { answers } }),
  latest: (token: string) => apiRequest<{ assessment: EligibilityAssessment | null }>('/eligibility-assessments/latest', { token }),
};
`);

// ========================================
// SESSION-SAFE API ACCESS
// Reuses the provider's private token and clears expired sessions consistently.
// ========================================
let auth = read('src/contexts/auth-context.tsx');
auth = replace(auth, "import { tokenStorage }", "import { eligibilityApi, type EligibilityAnswers, type EligibilityAssessment } from '@/services/eligibility';\nimport { tokenStorage }");
auth = replace(auth, '  logout: () => Promise<string | undefined>;', '  submitAssessment: (answers: EligibilityAnswers) => Promise<EligibilityAssessment>;\n  latestAssessment: () => Promise<EligibilityAssessment | null>;\n  logout: () => Promise<string | undefined>;');
auth = replace(auth, '  // LOGOUT\n', '  // LOGOUT\n');
const anchor = '  const loadProfile = useCallback(() => profileRequest(), [profileRequest]);';
auth = replace(auth, anchor, anchor + `

  // ========================================
  // PRE-SCREENING REQUESTS
  // Rejects responses from a signed-out session and clears revoked credentials.
  // ========================================
  const assessmentRequest = useCallback(async (answers?: EligibilityAnswers) => {
    const saved = token.current;
    if (!saved) throw new ApiError('Please log in again.', 401);
    try {
      const response = answers ? await eligibilityApi.submit(saved, answers) : await eligibilityApi.latest(saved);
      if (token.current !== saved) throw new ApiError('Your session changed. Please try again.', 401);
      return response.assessment;
    } catch (error) {
      if (error instanceof ApiError && error.status === 401 && token.current === saved) {
        try { await clearSession(); } catch (cleanupError) { setSessionError(errorMessage(cleanupError)); }
      }
      throw error;
    }
  }, [clearSession]);
  const latestAssessment = useCallback(() => assessmentRequest(), [assessmentRequest]);
  const submitAssessment = async (answers: EligibilityAnswers) => {
    const assessment = await assessmentRequest(answers);
    if (!assessment) throw new ApiError('The server did not return a saved assessment.');
    return assessment;
  };
`);
auth = replace(auth, '    loadProfile, saveProfile:', '    latestAssessment, submitAssessment,\n    loadProfile, saveProfile:');
changes.set('src/contexts/auth-context.tsx', auth);

// ========================================
// EXISTING EVALUATION FORM
// Preserves styles and navigation while replacing the mock submit action.
// ========================================
let form = read('src/app/evaluation.tsx');
form = replace(form, "import { useState } from 'react';", "import { useRef, useState } from 'react';\nimport { useAuth } from '@/contexts/auth-context';\nimport { errorMessage } from '@/services/api';\nimport { RESULT_LABELS, SCREENING_NOTICE, type EligibilityAnswers, type EligibilityAssessment } from '@/services/eligibility';");
form = form.replaceAll('recentIllness', 'currentSymptoms').replaceAll('recentDonation', 'donatedWithinThreeMonths');
form = replace(form, 'Have you had fever or illness recently?', 'Do you currently have fever, cough, colds, sore throat, or feel unwell?');
form = replace(form, 'Have you donated blood recently?', 'Have you donated blood within the last 3 months?');
form = replace(form, '  const router = useRouter();', `  const router = useRouter();
  // ========================================
  // SUBMISSION STATE
  // A synchronous lock blocks rapid duplicate taps while Laravel saves the result.
  // ========================================
  const { submitAssessment } = useAuth();
  const submitting = useRef(false);
  const [loading, setLoading] = useState(false);
  const [assessment, setAssessment] = useState<EligibilityAssessment | null>(null);
  const [error, setError] = useState('');`);
form = replace(form, '    setAnswers((current)', '    setAssessment(null);\n    setAnswers((current)');
form = replace(form, '  const submitEvaluation = () => {', `  // ========================================
  // VALIDATE AND SUBMIT ANSWERS
  // Frontend checks completeness only; the saved Laravel result is authoritative.
  // ========================================
  const submitEvaluation = async () => {
    if (submitting.current) return;`);
form = replace(form, 'parsedSleep < 0)', 'parsedSleep < 0 || parsedSleep > 24)');
form = replace(form, `    // TODO: Later this evaluation will be sent to Laravel, which will evaluate and store the
    // result and update the user's current status. The frontend will not make that decision.
    Alert.alert('Evaluation submitted for frontend testing.');`, `    submitting.current = true;
    setLoading(true);
    setError('');
    setAssessment(null);
    try {
      const saved = await submitAssessment({ weight: parsedWeight, sleepHours: parsedSleep,
        ...answers as Omit<EligibilityAnswers, 'weight' | 'sleepHours'> });
      setAssessment(saved);
    } catch (failure) { setError(errorMessage(failure)); }
    finally { submitting.current = false; setLoading(false); }`);
form = replace(form, 'Answer these questions for this frontend demonstration. This form does not provide\n                medical clearance.', 'Answer these questions for a LifeFlow pre-screening/self-assessment. Final eligibility\n                is confirmed by the blood donation facility.');
form = replace(form, 'onChangeText={setWeight}', 'editable={!loading}\n                  onChangeText={(value) => { setWeight(value); setAssessment(null); }}');
form = replace(form, 'onChangeText={setSleepHours}', 'editable={!loading}\n                  onChangeText={(value) => { setSleepHours(value); setAssessment(null); }}');
form = replace(form, 'accessibilityState={{ selected }}', 'accessibilityState={{ selected, disabled: loading }}\n                          disabled={loading}');
form = replace(form, '              onPress={submitEvaluation}', '              disabled={loading}\n              accessibilityState={{ disabled: loading, busy: loading }}\n              onPress={submitEvaluation}');
form = replace(form, '>Submit Evaluation</Text>', ">{loading ? 'Saving pre-screening…' : 'Submit Evaluation'}</Text>");
form = replace(form, '          <View style={styles.content}>', `          <View style={styles.content}>
            {error ? <Text accessibilityRole="alert" style={styles.noticeText}>{error}</Text> : null}
            {assessment ? (
              <View style={styles.noticeCard} accessibilityLiveRegion="polite">
                <View style={styles.questionContent}>
                  <Text style={styles.introTitle}>Pre-screening result</Text>
                  <Text style={styles.questionText}>{RESULT_LABELS[assessment.result]}</Text>
                  {assessment.reasons.map((reason, index) => <Text key={index} style={styles.noticeText}>{reason}</Text>)}
                  <Text style={styles.noticeText}>{SCREENING_NOTICE}</Text>
                </View>
              </View>
            ) : null}`);
form = replace(form, 'This prototype does not calculate or permanently update donation eligibility.', '{SCREENING_NOTICE}');
changes.set('src/app/evaluation.tsx', form);

// ========================================
// EXISTING STATUS SCREEN
// Refreshes the latest result on focus. Errors and empty history never imply eligibility.
// ========================================
let status = read('src/app/(tabs)/status.tsx');
status = replace(status, "import { useRouter } from 'expo-router';", "import { useFocusEffect, useRouter } from 'expo-router';\nimport { useCallback, useState } from 'react';\nimport { useAuth } from '@/contexts/auth-context';\nimport { errorMessage } from '@/services/api';\nimport { SCREENING_NOTICE, type EligibilityAssessment } from '@/services/eligibility';");
const start = status.indexOf('type EligibilityStatus');
const end = status.indexOf('const STATUS_PRESENTATION');
status = status.slice(0,start) + "type EligibilityStatus = 'ELIGIBLE' | 'NOT_ELIGIBLE' | 'EVALUATION_REQUIRED' | 'NEEDS_SCREENING';\n\n" + status.slice(end);
status = replace(status, 'You are eligible to donate!', 'Eligible at pre-screening');
status = replace(status, 'Based on your latest LifeFlow evaluation, you may proceed with donation planning.', 'Based on your saved answers. Final eligibility is confirmed by the blood donation facility.');
status = replace(status, 'You are temporarily not eligible to donate.', 'Temporarily ineligible at pre-screening');
status = replace(status, '  EVALUATION_REQUIRED: {', `  NEEDS_SCREENING: {
    title: 'Further screening needed',
    description: 'Medication use requires review by blood-donation staff.',
    icon: 'pending', background: COLORS.softAmber, color: COLORS.amber,
  },
  EVALUATION_REQUIRED: {`);
const summaryStart = status.indexOf('const SUMMARY_ITEMS');
const screenStart = status.indexOf('export default function StatusScreen');
status = status.slice(0,summaryStart) + status.slice(screenStart);
status = replace(status, `  const presentation = STATUS_PRESENTATION[MOCK_STATUS.status];
  const showReason = MOCK_STATUS.status === 'NOT_ELIGIBLE';`, `  // ========================================
  // LATEST SAVED PRE-SCREENING
  // Cancels stale screen updates on blur and never substitutes a mock result.
  // ========================================
  const { latestAssessment, profile, user } = useAuth();
  const [assessment, setAssessment] = useState<EligibilityAssessment | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  useFocusEffect(useCallback(() => {
    let active = true;
    setLoading(true); setError(''); setAssessment(null);
    latestAssessment().then((saved) => { if (active) setAssessment(saved); })
      .catch((failure) => { if (active) setError(errorMessage(failure)); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [latestAssessment, user?.id]));
  const state: EligibilityStatus = !assessment ? 'EVALUATION_REQUIRED'
    : assessment.result === 'eligible' ? 'ELIGIBLE'
    : assessment.result === 'needs_further_screening' ? 'NEEDS_SCREENING' : 'NOT_ELIGIBLE';
  const presentation = STATUS_PRESENTATION[state];
  const showReason = !!assessment?.reasons.length;
  const summaryItems = [
    { label: 'Assessed At', value: assessment ? new Date(assessment.assessed_at).toLocaleString() : 'Not assessed', icon: 'event' as const },
    { label: 'Weight', value: assessment ? assessment.answers.weight + ' kg' : 'Not assessed', icon: 'monitor-weight' as const },
    { label: 'Gender', value: profile?.gender || 'Not loaded', icon: 'person-outline' as const },
    { label: 'Blood Type', value: profile?.blood_type || 'Not loaded', icon: 'bloodtype' as const },
    { label: 'Last Donation', value: 'Not available', icon: 'event' as const },
    { label: 'Next Eligibility', value: 'Facility confirmation required', icon: 'update' as const },
  ];`);
status = replace(status, '{presentation.title}', "{loading ? 'Loading pre-screening…' : error ? 'Pre-screening unavailable' : presentation.title}");
status = replace(status, '{presentation.description}', '{error || presentation.description}');
status = replace(status, "{MOCK_STATUS.reason ?? 'Example reason from latest evaluation'}", "{assessment?.reasons.join('\\n\\n')}");
status = replace(status, "{MOCK_STATUS.advice ?? 'Complete another evaluation when your condition changes.'}", '{SCREENING_NOTICE}');
status = replace(status, 'SUMMARY_ITEMS.map', 'summaryItems.map');
status = replace(status, 'LifeFlow status is a planning guide based on your evaluation. It is not final medical\n              clearance to donate.', '{SCREENING_NOTICE}');
changes.set('src/app/(tabs)/status.tsx', status);

for (const [name, content] of changes) fs.writeFileSync(path.join(root, name), content);
console.log('Updated ' + [...changes.keys()].join(', '));
