<?php
// ========================================
// 2.php: 영화 임원 종합 정보 테이블
// ========================================
// 목적: 모든 영화 임원의 정보를 한 테이블에 표시
//       각 임원별로:
//         - 기본 정보 (순번, 이름, 주소)
//         - 사장으로 있는 영화사 목록
//         - 제작자로 참여한 영화 목록
//         - 배우로 출연한 영화 목록
// 특징: rowspan을 사용하여 중복 데이터 병합 (복잡한 테이블 레이아웃)
// ========================================

// ===== 에러 처리 함수 =====
function p_error($id=null) {
    if($id == null) 
        $e = oci_error();
    else 
        $e = oci_error($id);
    print htmlentities($e['message']);
    exit();
}

// ===== rowspan 계산 함수 =====
/**
 * 배열에서 연속된 동일 값의 rowspan을 계산
 * 
 * @param array $arr - 값의 배열
 * @return array - rowspan 정보 배열
 * 
 * 목적: 테이블에서 같은 값이 연속될 때 셀을 병합하여 가독성 향상
 * 
 * 예시:
 * 입력: ['A', 'A', 'A', 'B', 'B', 'C']
 * 출력: [
 *   0 => ['value' => 'A', 'span' => 3],  // 인덱스 0에서 A가 3번 연속
 *   3 => ['value' => 'B', 'span' => 2],  // 인덱스 3에서 B가 2번 연속
 *   5 => ['value' => 'C', 'span' => 1]   // 인덱스 5에서 C가 1번
 * ]
 * 
 * 테이블 렌더링 시:
 * - 인덱스 0: <TD rowspan=3>A</TD> (3행을 차지)
 * - 인덱스 1, 2: 셀 출력 안 함 (이미 병합됨)
 * - 인덱스 3: <TD rowspan=2>B</TD> (2행을 차지)
 * - 인덱스 4: 셀 출력 안 함
 * - 인덱스 5: <TD rowspan=1>C</TD> (1행만 차지)
 */
function calculate_rowspans($arr) {
    $rowspans = array();  // 결과 배열
    $i = 0;
    
    while($i < count($arr)) {
        $current_value = $arr[$i];  // 현재 값
        $span = 1;                  // 연속 횟수 (최소 1)
        $j = $i + 1;
        
        // 다음 값들과 비교하여 같으면 span 증가
        while($j < count($arr) && $arr[$j] === $current_value) {
            $span++;
            $j++;
        }
        
        // 시작 인덱스에 값과 span 저장
        $rowspans[$i] = array('value' => $current_value, 'span' => $span);
        
        // 다음 다른 값의 시작 위치로 이동
        $i = $j;
    }
    
    return $rowspans;
}

// ===== 데이터베이스 연결 =====
$conn = oci_connect("db2021663028","db88602924", "localhost/lecture");
if (!$conn) p_error();

// ========================================
// 1단계: 모든 데이터 조회 및 메모리 로드
// ========================================
// 4개의 쿼리를 실행하여 모든 관련 데이터를 메모리에 로드
// 이유: PHP에서 데이터를 조합하고 매핑하기 위함

// ----- 1-1. 모든 임원 정보 조회 -----
$stmt = oci_parse($conn, "select certno, name, address from movieexec order by name");
if (!$stmt) p_error($conn);
$r = oci_execute($stmt);
if (!$r) p_error($stmt);
// oci_fetch_all(): 모든 결과를 배열로 가져옴
// $exec_cnt: 총 임원 수
$exec_cnt = oci_fetch_all($stmt, $execs, null, null, OCI_FETCHSTATEMENT_BY_ROW);

// ----- 1-2. 모든 스튜디오 정보 조회 -----
// presno: 사장의 인증번호 (movieexec.certno와 매핑)
$stmt2 = oci_parse($conn, "select presno, name from studio order by name");
if (!$stmt2) p_error($conn);
$r = oci_execute($stmt2);
if (!$r) p_error($stmt2);
$studio_cnt = oci_fetch_all($stmt2, $studios, null, null, OCI_FETCHSTATEMENT_BY_ROW);

// ----- 1-3. 모든 영화 정보 조회 -----
// producerno: 제작자의 인증번호 (movieexec.certno와 매핑)
$stmt3 = oci_parse($conn, "select producerno, title, year from movie order by year, title");
if (!$stmt3) p_error($conn);
$r = oci_execute($stmt3);
if (!$r) p_error($stmt3);
$movie_cnt = oci_fetch_all($stmt3, $movies, null, null, OCI_FETCHSTATEMENT_BY_ROW);

// ----- 1-4. 모든 출연 정보 조회 -----
// starname: 배우 이름 (movieexec.name과 매핑 가능)
$stmt4 = oci_parse($conn, "select starname, movietitle, movieyear from starsin order by movieyear, movietitle");
if (!$stmt4) p_error($conn);
$r = oci_execute($stmt4);
if (!$r) p_error($stmt4);
$star_cnt = oci_fetch_all($stmt4, $stars, null, null, OCI_FETCHSTATEMENT_BY_ROW);

// ========================================
// 2단계: 데이터 매핑 (해시맵 생성)
// ========================================
// 각 임원이 관련된 스튜디오, 영화, 출연작을 빠르게 찾기 위한 매핑 구조 생성

// ----- 2-1. 스튜디오 맵 생성 -----
// $studio_map[presno] = [스튜디오 이름 배열]
// 예: $studio_map[123] = ['Universal', 'Paramount']
//     → 123번 임원이 사장으로 있는 스튜디오들
$studio_map = array();
for($i = 0; $i < $studio_cnt; $i++) {
    $presno = $studios[$i]['PRESNO'];
    
    // 배열 초기화 (첫 번째 항목인 경우)
    if(!$studio_map[$presno]) 
        $studio_map[$presno] = array();
    
    // 스튜디오 이름 추가
    $studio_map[$presno][] = $studios[$i]['NAME'];
}

// ----- 2-2. 영화 맵 생성 -----
// $movie_map[producerno] = [영화 정보 배열]
// 예: $movie_map[456] = ['Avatar(2009)', 'Titanic(1997)']
//     → 456번 임원이 제작한 영화들
$movie_map = array();
for($i = 0; $i < $movie_cnt; $i++) {
    $pid = $movies[$i]['PRODUCERNO'];
    
    // producerno가 NULL인 경우 건너뛰기
    if(empty($pid)) continue;
    
    // 배열 초기화
    if(!$movie_map[$pid]) 
        $movie_map[$pid] = array();
    
    // "제목(연도)" 형식으로 추가
    $movie_map[$pid][] = $movies[$i]['TITLE']."(".$movies[$i]['YEAR'].")";
}

// ----- 2-3. 출연작 맵 생성 -----
// $star_map[starname] = [출연 영화 배열]
// 예: $star_map['Tom Hanks'] = ['Forrest Gump(1994)', 'Cast Away(2000)']
//     → Tom Hanks가 출연한 영화들
// 주의: 이름이 같은 임원과 배우를 매핑 (임원이 배우로도 활동)
$star_map = array();
for($i = 0; $i < $star_cnt; $i++) {
    $sname = $stars[$i]['STARNAME'];
    
    // 배열 초기화
    if(!$star_map[$sname]) 
        $star_map[$sname] = array();
    
    // "제목(연도)" 형식으로 추가
    $star_map[$sname][] = $stars[$i]['MOVIETITLE']."(".$stars[$i]['MOVIEYEAR'].")";
}

// ========================================
// 3단계: HTML 테이블 출력
// ========================================

// ----- 테이블 헤더 -----
print "<TABLE bgcolor=#E3F2FD border=1 cellspacing=2>\n";
print "<TR bgcolor=#90CAF9 align=center><TH> 순번 <TH> 이름 <TH> 주소 <TH> 영화사 <TH> 제작 영화 <TH> 출연 영화</TR>\n";

// ----- 각 임원에 대한 행 출력 -----
for($i = 0; $i < $exec_cnt; $i++) {
    $certno = $execs[$i]['CERTNO'];    // 임원 인증번호
    $name = $execs[$i]['NAME'];        // 임원 이름
    $address = $execs[$i]['ADDRESS'];  // 임원 주소
    
    // ----- 이 임원과 관련된 데이터 가져오기 -----
    // 맵에서 데이터가 없으면 빈 배열 반환
    $my_studios = $studio_map[$certno] ? $studio_map[$certno] : array();  // 사장으로 있는 스튜디오
    $my_movies = $movie_map[$certno] ? $movie_map[$certno] : array();     // 제작한 영화
    $my_stars = $star_map[$name] ? $star_map[$name] : array();            // 출연한 영화 (이름 매칭)
    
    // ----- 필요한 행 수 계산 -----
    // 가장 많은 데이터를 가진 컬럼의 개수가 필요한 행 수
    // 최소 1행은 필요 (기본 정보 표시)
    $max_rows = max(1, count($my_studios), count($my_movies), count($my_stars));
    
    // ----- 데이터 배열 정규화 -----
    // 목적: 모든 배열의 길이를 $max_rows로 맞추기
    // 3가지 케이스 처리:
    //   1) 데이터 없음: 모든 행에 "없음" 표시
    //   2) 데이터 1개: 모든 행에 같은 값 표시 (rowspan으로 병합)
    //   3) 데이터 여러 개: 부족한 만큼 빈 문자열 추가
    
    // --- 스튜디오 배열 정규화 ---
    if(count($my_studios) == 0) {
        // 케이스 1: 데이터 없음
        $my_studios = array_fill(0, $max_rows, "없음");
    } else if(count($my_studios) == 1) {
        // 케이스 2: 1개만 있음 (모든 행에 복제)
        $my_studios = array_fill(0, $max_rows, $my_studios[0]);
    } else {
        // 케이스 3: 여러 개 있음 (부족한 만큼 빈 문자열 추가)
        while(count($my_studios) < $max_rows) {
            $my_studios[] = "";
        }
    }
    
    // --- 영화 배열 정규화 ---
    if(count($my_movies) == 0) {
        $my_movies = array_fill(0, $max_rows, "없음");
    } else if(count($my_movies) == 1) {
        $my_movies = array_fill(0, $max_rows, $my_movies[0]);
    } else {
        while(count($my_movies) < $max_rows) {
            $my_movies[] = "";
        }
    }
    
    // --- 출연작 배열 정규화 ---
    if(count($my_stars) == 0) {
        $my_stars = array_fill(0, $max_rows, "없음");
    } else if(count($my_stars) == 1) {
        $my_stars = array_fill(0, $max_rows, $my_stars[0]);
    } else {
        while(count($my_stars) < $max_rows) {
            $my_stars[] = "";
        }
    }
    
    // ----- rowspan 정보 계산 -----
    // 각 배열에서 연속된 동일 값의 rowspan 계산
    $studio_rowspans = calculate_rowspans($my_studios);
    $movie_rowspans = calculate_rowspans($my_movies);
    $star_rowspans = calculate_rowspans($my_stars);
    
    // ----- 첫 번째 행 출력 -----
    // 순번, 이름, 주소는 항상 전체 행을 차지 (rowspan=$max_rows)
    print "<TR> <TD rowspan=$max_rows> ".($i+1)." <TD rowspan=$max_rows> $name <TD rowspan=$max_rows> $address ";
    
    // 스튜디오: 첫 번째 rowspan 정보가 있으면 출력
    if(isset($studio_rowspans[0])) {
        print "<TD rowspan=".$studio_rowspans[0]['span']."> ".$studio_rowspans[0]['value']." ";
    }
    
    // 영화: 첫 번째 rowspan 정보가 있으면 출력
    if(isset($movie_rowspans[0])) {
        print "<TD rowspan=".$movie_rowspans[0]['span']."> ".$movie_rowspans[0]['value']." ";
    }
    
    // 출연작: 첫 번째 rowspan 정보가 있으면 출력
    if(isset($star_rowspans[0])) {
        print "<TD rowspan=".$star_rowspans[0]['span']."> ".$star_rowspans[0]['value']." ";
    }
    
    print "</TR>\n";
    
    // ----- 나머지 행들 출력 -----
    // 두 번째 행부터 $max_rows까지 반복
    for($j = 1; $j < $max_rows; $j++) {
        print "<TR>";
        
        // 순번, 이름, 주소는 이미 rowspan으로 처리되었으므로 출력 안 함
        
        // 스튜디오: 이 인덱스가 rowspan 시작점이면 출력
        if(isset($studio_rowspans[$j])) {
            print "<TD rowspan=".$studio_rowspans[$j]['span']."> ".$studio_rowspans[$j]['value']." ";
        }
        
        // 영화: 이 인덱스가 rowspan 시작점이면 출력
        if(isset($movie_rowspans[$j])) {
            print "<TD rowspan=".$movie_rowspans[$j]['span']."> ".$movie_rowspans[$j]['value']." ";
        }
        
        // 출연작: 이 인덱스가 rowspan 시작점이면 출력
        if(isset($star_rowspans[$j])) {
            print "<TD rowspan=".$star_rowspans[$j]['span']."> ".$star_rowspans[$j]['value']." ";
        }
        
        print "</TR>\n";
    }
}

// ----- 테이블 종료 -----
print "</TABLE>\n";

// ===== 리소스 정리 =====
oci_free_statement($stmt);
oci_free_statement($stmt2);
oci_free_statement($stmt3);
oci_free_statement($stmt4);
oci_close($conn);

?>

<!-- 
========================================
테이블 레이아웃 예시:
========================================

+----+----------+------------+-------------+------------------+----------------+
|순번| 이름     | 주소       | 영화사      | 제작 영화        | 출연 영화      |
+----+----------+------------+-------------+------------------+----------------+
| 1  | James    | LA         | Universal   | Avatar(2009)     | 없음           |
|    |          |            |             | Titanic(1997)    |                |
+----+----------+------------+-------------+------------------+----------------+
| 2  | Tom      | NY         | 없음        | 없음             | Cast Away(2000)|
|    |          |            |             |                  | Big(1988)      |
|    |          |            |             |                  | Big(1988)      |  ← 중복 rowspan=2
+----+----------+------------+-------------+------------------+----------------+

rowspan 처리:
- James의 순번/이름/주소: rowspan=2 (2행 차지)
- James의 Universal: rowspan=2 (데이터가 1개지만 2행 필요)
- James의 Avatar, Titanic: 각각 rowspan=1
- Tom의 Big: rowspan=2 (연속 2개가 같은 값)

========================================
알고리즘 복잡도:
========================================
시간 복잡도: O(E + S + M + St)
  E: 임원 수
  S: 스튜디오 수
  M: 영화 수
  St: 출연 정보 수

공간 복잡도: O(E + S + M + St)
  모든 데이터를 메모리에 로드

장점:
- 단일 페이지 로드로 모든 정보 표시
- 복잡한 관계를 직관적으로 시각화

단점:
- 데이터가 많으면 메모리 사용량 증가
- 페이지 로딩 시간 증가 가능

========================================
데이터 정규화 이유:
========================================

문제:
- 임원 A: 스튜디오 2개, 영화 5개, 출연작 1개
- 필요한 행 수: max(2, 5, 1) = 5행

해결:
- 스튜디오 배열: [S1, S2] → [S1, S2, "", "", ""]
- 영화 배열: [M1, M2, M3, M4, M5] (그대로)
- 출연작 배열: [Star1] → [Star1, Star1, Star1, Star1, Star1]

결과:
- 모든 배열이 5개 요소를 가짐
- 인덱스로 동기화된 출력 가능

========================================
rowspan 최적화:
========================================

최적화 전:
<TD>Star1</TD>
<TD>Star1</TD>
<TD>Star1</TD>
<TD>Star1</TD>
<TD>Star1</TD>

최적화 후:
<TD rowspan=5>Star1</TD>
(나머지 4행에서는 TD 출력 안 함)

효과:
- HTML 크기 감소
- 시각적 명확성 향상
- 테이블 가독성 증가

========================================
-->
