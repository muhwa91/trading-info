/**
 * 세션 배지(정규장·프리마켓·애프터마켓·주간거래·장마감 …) 단일 소스.
 *
 * 크기 = DESIGN.md §5 배지 규격 (h-[22px] px-2 rounded-xs text-2xs font-medium tracking-wide).
 * 색은 3계층 시맨틱 토큰: 정규장=ses-open(앰버) / 연장=ses-ext(틸) / 마감·기타=중립 muted.
 *
 * 사용처별 레이아웃 요구(whitespace-nowrap·shrink-0·tracking 등)만 각자 덧붙인다.
 * 크기·색 규칙은 여기서만 바꾼다 — 클래스 문자열을 컴포넌트에 복제하지 말 것.
 */

export const SESSION_BADGE_BASE =
  'inline-flex items-center justify-center h-[22px] px-2 rounded-xs border text-2xs font-medium tracking-wide leading-tight';

const TONE_OPEN   = 'text-ses-open bg-ses-open-weak border-ses-open-line';
const TONE_EXT    = 'text-ses-ext bg-ses-ext-weak border-ses-ext-line';
// 🔴 `text-base-content/40` 으로 되돌리지 말 것 — WCAG AA 미달이다 (2026-08-16 실측).
//   투명도는 «합성 후» 실효색으로 재야 한다: 배지 배경이 `bg-base-200/40` on base-100 = #121923 이고,
//   그 위의 base-content/40 은 실효 #676D75 라 **3.38:1**(AA 는 4.5 필요). 불투명 muted 는 6.89:1.
//   원색만 보면 #E6EAF0 on #141B26 = 13:1 로 읽혀 문제가 안 보인다 — 이 결함이 오래 남은 이유다.
//   ⚠️ 다른 두 배지와 비교할 땐 **각자 자기 배경 위에서** 재라 — 정규장·연장은
//   `bg-base-200/40` 이 아니라 자기 weak 배경(alpha 0.10)을 쓴다. 그 위에서 정규장 6.75:1 ·
//   연장 6.03:1 로 원래 통과였고, 마감 배지 하나만 미달이었다.
//   style.css:68 이 이미 "마감/휴장은 중립 토큰(muted) 재사용"이라 적어 둔 대로 맞춘 것이다.
const TONE_CLOSED = 'text-muted bg-base-200/40 border-base-content/10';

// 연장 세션 라벨 — 백엔드 session / indexQuoteLabel 이 내는 표기를 모두 수용
const EXT_LABELS = ['프리마켓', '애프터마켓', '주간거래', '야간거래', '거래중', '야간 거래중'];

/**
 * 세션 한글 라벨 → 색 토큰 클래스.
 * @param {string} label 예: '정규장' | '프리마켓' | '장마감' | '전일 마감'
 */
export function sessionBadgeTone(label) {
  if (label === '정규장') return TONE_OPEN;
  if (EXT_LABELS.includes(label)) return TONE_EXT;
  return TONE_CLOSED;
}

/**
 * US 연장세션 헤드라인 등락률 앞에 붙일 세션 라벨(토스식 "애프터마켓에서" 표기).
 * change_percent 가 어느 세션 기준인지 알려주는 접두 라벨이며, 숫자는 백엔드값 그대로 쓴다.
 * 정규장·장마감 등 연장세션이 아니면 '' (라벨 없음 → 헤드라인 1줄 유지).
 * @param {string} usSession PRE | AFT | EXT_NIGHT | REGULAR | CLOSED | ''
 */
export function usExtHeadlineLabel(usSession) {
  if (usSession === 'PRE') return '프리마켓에서';
  if (usSession === 'AFT') return '애프터마켓에서';
  if (usSession === 'EXT_NIGHT') return '주간거래에서';
  return '';
}
