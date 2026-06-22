/**
 * 차트 오버레이 좌표 가시성 판정 헬퍼
 *
 * @param {number|null} coord - priceToCoordinate 반환값(px 단위, 위쪽이 0)
 * @param {number} chartHeight - 차트 캔버스 높이(px)
 * @returns {boolean} 0 이상이고 chartHeight 이하인 경우만 true (상단·하단 모두 클리핑)
 *
 * qa-tester 단위 테스트:
 *   isCoordinateVisible(null, 300)   // false
 *   isCoordinateVisible(-1, 300)     // false (상단 벗어남)
 *   isCoordinateVisible(0, 300)      // true  (상단 경계)
 *   isCoordinateVisible(150, 300)    // true  (가시 범위 내)
 *   isCoordinateVisible(300, 300)    // true  (하단 경계)
 *   isCoordinateVisible(301, 300)    // false (하단 벗어남)
 */
export function isCoordinateVisible(coord, chartHeight) {
  if (coord === null || coord === undefined) return false;
  return coord >= 0 && coord <= chartHeight;
}
