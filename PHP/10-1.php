<?php
// ========================================
// 1.php: 영화 검색 결과 처리 및 표시
// ========================================
// 목적: 1.html에서 전달받은 검색 조건으로 데이터베이스 쿼리 실행 후
//       조건에 맞는 영화 목록을 HTML 테이블로 출력
// 입력: POST 방식으로 전달된 검색 조건
// 출력: 검색 결과 테이블 (iframe에 표시)
// ========================================

// ===== 에러 처리 함수 =====
/**
 * Oracle 에러 메시지를 출력하고 스크립트 종료
 * 
 * @param resource|null $id - OCI 리소스 핸들
 */
function p_error($id=null) {
    if($id == null) 
        $e = oci_error();      // 전역 에러
    else 
        $e = oci_error($id);   // 특정 리소스의 에러
    print htmlentities($e['message']);
    exit();
}

// ===== 데이터베이스 연결 =====
$conn = oci_connect("db2021663028","db88602924", "localhost/lecture");
if (!$conn) p_error();

// ===== POST 데이터 수신 =====
// 1.html 폼에서 전달된 모든 검색 조건을 변수에 저장

// 제목 관련
$title = trim($_POST["title"]);              // 앞뒤 공백 제거
$use_like = $_POST["use_like"];              // LIKE 사용 여부 (체크 시 "1")
$case_sensitive = $_POST["case_sensitive"];  // 대소문자 구별 여부 (체크 시 "1")

// 상영시간 관련
$length_min = $_POST["length_min"];          // 최소 상영시간
$length_max = $_POST["length_max"];          // 최대 상영시간

// 배우 관련
$birth_year = $_POST["birth_year"];          // 출생 연도
$gender = $_POST["gender"];                  // 성별 ("M" 또는 "F")

// ===== 동적 SQL 조건 문자열 초기화 =====
// WHERE 절에 추가될 조건들을 저장할 변수
$cond = "";

// ===== 1. 제목 검색 조건 처리 =====
if(!empty($title)) {
    // 제목이 입력된 경우만 처리
    
    // ----- SQL 인젝션 및 LIKE 특수문자 이스케이프 -----
    // 1) 작은따옴표(') 이스케이프: SQL 인젝션 방지
    $esc = str_replace("'", "''", $title);
    
    // 2) 언더스코어(_) 이스케이프: LIKE에서 와일드카드로 인식되므로
    //    리터럴 문자로 처리하기 위해 백슬래시 추가
    $esc = str_replace("_", "\\_", $esc);
    
    // 3) 퍼센트(%) 이스케이프: LIKE에서 와일드카드로 인식되므로
    //    리터럴 문자로 처리하기 위해 백슬래시 추가
    $esc = str_replace("%", "\\%", $esc);
    
    // ----- LIKE vs 완전 일치 처리 -----
    if(!empty($use_like)) {
        // LIKE 체크박스가 선택된 경우: 부분 일치 검색
        
        if(!empty($case_sensitive)) {
            // 대소문자 구별하여 검색
            // '%'||'$esc'||'%': 제목에 검색어가 포함되어 있으면 매치
            // escape '\\': 백슬래시를 이스케이프 문자로 사용
            $cond = $cond."and title like '%'||'$esc'||'%' escape '\\' ";
        } else {
            // 대소문자 무시하고 검색
            // upper(): 대소문자를 대문자로 통일하여 비교
            $cond = $cond."and upper(title) like upper('%'||'$esc'||'%') escape '\\' ";
        }
        
    } else {
        // LIKE 체크박스가 선택되지 않은 경우: 완전 일치 검색
        
        if(!empty($case_sensitive)) {
            // 대소문자 구별하여 완전 일치
            $cond = $cond."and title = '$esc' ";
        } else {
            // 대소문자 무시하고 완전 일치
            $cond = $cond."and upper(title) = upper('$esc') ";
        }
    }
}

// ===== 2. 상영시간 범위 조건 처리 =====
// 최소 상영시간이 입력된 경우
if(!empty($length_min)) {
    $cond = $cond."and length >= $length_min ";
}

// 최대 상영시간이 입력된 경우
if(!empty($length_max)) {
    $cond = $cond."and length <= $length_max ";
}

// ===== 3. 배우 출생 연도 및 성별 조건 처리 =====
if(!empty($birth_year)) {
    // 출생 연도가 입력된 경우
    
    // ----- 성별 조건 생성 -----
    $gender_cond = "";
    if(!empty($gender)) {
        // 성별 라디오 버튼이 선택된 경우
        // trim(): 성별 데이터의 앞뒤 공백 제거
        $gender_cond = " and trim(ms.gender) = '$gender' ";
    }
    
    // ----- EXISTS 서브쿼리 생성 -----
    // EXISTS: 조건을 만족하는 행이 하나라도 있으면 TRUE
    // 목적: 특정 연도 이후 태어난 배우(+성별 조건)가 출연한 영화만 필터링
    $cond = $cond."and exists (select 1 from starsin si, moviestar ms ".
           "where si.movietitle = m.title and si.movieyear = m.year ".  // 영화-출연 조인
           "and si.starname = ms.name ".                                 // 출연-배우 조인
           "and extract(year from ms.birthdate) > $birth_year ".         // 연도 추출 및 비교
           "$gender_cond) ";                                             // 성별 조건 추가
    
    // extract(year from ms.birthdate): DATE에서 연도만 추출
    // 예: extract(year from '1995-05-15') = 1995
}

// ===== 메인 쿼리 생성 및 실행 =====
$stmt = oci_parse($conn,
    // SELECT 절: 영화 정보, 제작자, 스튜디오 정보
    "select m.title, m.year, m.length, e.name producer, m.studioname, s.address ".
    // FROM 절: 3개 테이블 조인
    "from movie m, studio s, movieexec e ".
    // WHERE 절: 기본 조인 조건 + 동적 검색 조건
    "where m.studioname = s.name ".          // 영화-스튜디오 조인
    "and s.presno = e.certno ".              // 스튜디오-사장 조인
    "$cond ".                                // 동적으로 생성된 검색 조건
    // ORDER BY 절: 제목순, 연도순 정렬
    "order by m.title, m.year");

if (!$stmt) p_error($conn);

// 쿼리 실행
$r = oci_execute($stmt);
if (!$r) p_error($stmt);

// ===== 결과 일괄 가져오기 =====
// oci_fetch_all(): 모든 결과를 배열로 가져옴
// OCI_FETCHSTATEMENT_BY_ROW: 행 단위로 데이터 구성
// 반환값: 가져온 행의 개수
$nrows = oci_fetch_all($stmt, $results, null, null, OCI_FETCHSTATEMENT_BY_ROW);

// ===== HTML 테이블 헤더 출력 =====
// 파란색 계열 색상 사용 (#E3F2FD: 연한 파랑, #90CAF9: 파랑)
print "<TABLE bgcolor=#E3F2FD border=1 cellspacing=2>\n";
print "<TR bgcolor=#90CAF9 align=center><TH> 영화제목 <TH> 개봉년도 <TH> 상영시간 <TH> 제작자 이름 <TH> 영화사 이름 <TH> 영화사 주소</TR>\n";

// ===== 검색 결과 출력 루프 =====
for($i = 0; $i < $nrows; $i++) {
    $row = $results[$i];
    $movie_title = $row['TITLE'];
    
    // ----- 검색어 하이라이트 처리 -----
    // 제목 검색이 LIKE로 수행된 경우, 일치하는 부분을 노란색 배경에 빨간색 글자로 강조
    if(!empty($title) && !empty($use_like)) {
        
        if(!empty($case_sensitive)) {
            // 대소문자 구별하는 경우: 정확한 위치 찾기
            
            // strpos(): 문자열에서 검색어의 위치 찾기 (대소문자 구별)
            $pos = strpos($movie_title, $title);
            
            if($pos !== false) {
                // 검색어가 발견된 경우
                
                // 제목을 3부분으로 분리: 앞부분 + 일치부분 + 뒷부분
                $before = substr($movie_title, 0, $pos);                      // 앞부분
                $match = substr($movie_title, $pos, strlen($title));          // 일치부분
                $after = substr($movie_title, $pos + strlen($title));         // 뒷부분
                
                // 일치부분만 하이라이트 적용하여 재조합
                $movie_title = $before."<font style='background:yellow;color:red'><b>".$match."</b></font>".$after;
            }
            
        } else {
            // 대소문자 무시하는 경우: 정규표현식 사용
            
            // preg_replace(): 정규표현식 기반 문자열 치환
            // "/(".$title.")/i": 대소문자 무시 패턴 (i 플래그)
            // preg_quote(): 특수문자 이스케이프
            // $1: 일치한 문자열 (원본 대소문자 유지)
            $movie_title = preg_replace("/(".preg_quote($title, '/').")/i", 
                                       "<font style='background:yellow;color:red'><b>$1</b></font>", 
                                       $movie_title);
        }
    }
    
    // ----- 행 출력 -----
    print "<TR> <TD> $movie_title <TD> {$row['YEAR']}년 <TD> {$row['LENGTH']}분 "
            . "<TD> {$row['PRODUCER']} <TD> <font color=blue><b>{$row['STUDIONAME']}</b></font> "
            . "<TD> {$row['ADDRESS']} </TR>\n";
    // 영화사 이름은 파란색 굵은 글씨로 강조
}

// ===== 검색 결과 없음 처리 =====
if($nrows == 0) {
    // colspan=6: 6개 컬럼을 병합하여 메시지 표시
    print "<TR><TD colspan=6 align=center> 검색 조건에 맞는 영화가 없습니다 </TD></TR>\n";
}

// ===== 테이블 종료 =====
print "</TABLE>\n";

// ===== 리소스 정리 =====
oci_free_statement($stmt);
oci_close($conn);

?>

<!-- 
========================================
검색 로직 흐름:
========================================

[1단계] POST 데이터 수신
   - title, use_like, case_sensitive
   - length_min, length_max
   - birth_year, gender

[2단계] 동적 SQL 조건 생성 ($cond)
   - 제목 조건 추가
   - 상영시간 조건 추가
   - 배우 조건 추가 (EXISTS 서브쿼리)

[3단계] 메인 쿼리 실행
   - movie + studio + movieexec 조인
   - 동적 조건 적용

[4단계] 결과 출력
   - HTML 테이블 생성
   - 검색어 하이라이트
   - 결과 없으면 메시지 표시

========================================
SQL 인젝션 방지:
========================================
문제 상황:
- 사용자 입력: O'Brien
- 이스케이프 없이 사용: title = 'O'Brien'
- SQL 에러 발생 (작은따옴표 충돌)

해결 방법:
- str_replace("'", "''", $title)
- O'Brien → O''Brien
- SQL: title = 'O''Brien' (정상 실행)

LIKE 와일드카드 이스케이프:
- 사용자 입력: 100%_test
- 이스케이프 없이: LIKE '%100%_test%'
  → %와 _가 와일드카드로 인식
- 이스케이프 후: LIKE '%100\%\_test%' ESCAPE '\'
  → 리터럴 문자로 인식

========================================
검색어 하이라이트 로직:
========================================

대소문자 구별 (case_sensitive):
1. strpos()로 정확한 위치 찾기
2. substr()로 문자열 3등분
3. 중간 부분만 하이라이트 태그로 감싸기

대소문자 무시:
1. preg_replace()로 정규표현식 매칭
2. /i 플래그로 대소문자 무시
3. $1로 원본 대소문자 유지하며 치환

하이라이트 스타일:
- background:yellow: 노란색 배경
- color:red: 빨간색 글자
- <b>: 굵은 글씨

========================================
EXISTS 서브쿼리 설명:
========================================

목적:
특정 조건의 배우가 출연한 영화만 필터링

SQL:
exists (
  select 1 
  from starsin si, moviestar ms 
  where si.movietitle = m.title 
    and si.movieyear = m.year
    and si.starname = ms.name 
    and extract(year from ms.birthdate) > 1990
    and trim(ms.gender) = 'F'
)

동작:
1. 메인 쿼리의 각 영화에 대해
2. 조건을 만족하는 배우가 있는지 확인
3. 하나라도 있으면 TRUE → 영화 포함
4. 없으면 FALSE → 영화 제외

장점:
- 효율적 (1개만 찾으면 중단)
- 가독성 좋음
- 표준 SQL

========================================
출력 예시:
========================================

검색 조건:
- title: "star", LIKE 사용, 대소문자 무시
- length_min: 100, length_max: 150

결과 테이블:
+------------------+--------+--------+--------+-----------+-------------+
| 영화제목         | 연도   | 시간   | 제작자 | 영화사    | 주소        |
+------------------+--------+--------+--------+-----------+-------------+
| A **Star** is Born| 2018년 | 136분 | Bradley| Warner    | Burbank, CA |
| **Star** Wars    | 1977년 | 121분 | George | Lucasfilm | SF, CA      |
+------------------+--------+--------+--------+-----------+-------------+

** 부분이 노란색 배경에 빨간색 굵은 글씨로 표시됨

========================================
-->
