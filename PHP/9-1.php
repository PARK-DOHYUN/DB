<?php
// ========================================
// 1.php: 영화 목록 조회 및 출연 배우 정보 표시
// ========================================
// 목적: movie 테이블의 모든 영화 정보를 조회하고,
//       각 영화의 제작자, 영화사 사장, 출연 배우 목록을 함께 표시
// ========================================

// ===== 한글 인코딩 설정 (선택사항) =====
// Oracle 데이터베이스의 한글 데이터를 올바르게 처리하기 위한 환경 변수
// 주석 처리되어 있지만, 한글 깨짐 현상 발생 시 활성화 가능
//putenv("NLS_LANG=KOREAN_KOREA.AL32UTF8");

// ===== 에러 처리 함수 =====
/**
 * Oracle 에러 메시지를 출력하고 스크립트 종료
 * 
 * @param resource|null $id - OCI 리소스 핸들 (connection 또는 statement)
 *                           null인 경우 전역 에러 확인
 */
function p_error($id=null){
    // $id가 null이면 연결 전 에러, 아니면 특정 리소스의 에러
    if($id == null) 
        $e = oci_error();      // 전역 에러 (주로 연결 실패)
    else 
        $e = oci_error($id);   // 특정 리소스의 에러 (파싱, 실행 실패 등)
    
    // HTML 특수문자를 엔티티로 변환하여 안전하게 출력
    // (XSS 공격 방지 및 올바른 HTML 렌더링)
    print htmlentities($e['message']);
    exit();  // 스크립트 즉시 종료
}

// ===== 데이터베이스 연결 =====
// Oracle 데이터베이스에 연결
// 형식: oci_connect(사용자명, 비밀번호, 연결문자열)
$conn = oci_connect("db2021663028","db88602924", "localhost/lecture");

// 연결 실패 시 에러 메시지 출력 후 종료
if(!$conn) p_error();

// ===== 메인 쿼리 준비 =====
// 영화 정보와 관련된 제작자, 사장, 출연 배우 수를 조회하는 복잡한 쿼리
$stmt = oci_parse($conn,
    // SELECT 절: 조회할 컬럼들
    "select m.title, m.year, m.length, p.name producer, e.name boss, ".
    // 서브쿼리: 각 영화의 출연 배우 수를 계산
    // starsin 테이블에서 해당 영화(제목+연도)에 출연한 배우의 수를 카운트
    "(select count(*) from starsin si where si.movietitle = m.title and si.movieyear = m.year) actor_cnt ".
    // FROM 절: 4개 테이블 조인
    "from movie m, movieexec p, studio s, movieexec e ".
    // WHERE 절: 테이블 조인 조건
    "where m.producerno = p.certno ".     // 영화의 제작자 정보 조인
    "and m.studioname = s.name ".         // 영화의 스튜디오 정보 조인
    "and s.presno = e.certno ".           // 스튜디오의 사장 정보 조인
    // ORDER BY 절: 개봉 연도 순, 같은 연도면 상영시간 순으로 정렬
    "order by m.year, m.length");

// 파싱 실패 시 에러 처리
if(!$stmt) p_error($conn);

// ===== 쿼리 실행 =====
$r = oci_execute($stmt);

// 실행 실패 시 에러 처리
if(!$r) p_error($conn);

// ===== HTML 테이블 헤더 출력 =====
// 핑크색 배경, 1픽셀 테두리, 3픽셀 셀 간격의 테이블 시작
print "<TABLE bgcolor='pink' border=1 cellspacing=3>\n";

// 빨간색 배경의 헤더 행 (중앙 정렬)
print "<TR bgcolor='red' align=center><TH>제목<TH>년도<TH>상영시간<TH>제작자<TH>영화사사장<TH>출연배우수<TH>출연배우진</TR>\n";

// ===== 메인 루프: 각 영화 정보 출력 =====
while ($row = oci_fetch_array($stmt)) {
    // 각 컬럼 값을 변수에 저장 (가독성 향상)
    $title = $row[0];        // 영화 제목
    $year = $row[1];         // 개봉 연도
    $length = $row[2];       // 상영 시간 (분)
    $producer = $row[3];     // 제작자 이름
    $boss = $row[4];         // 영화사 사장 이름
    $actor_cnt = $row[5];    // 출연 배우 수
    
    // ===== SQL 인젝션 방지 =====
    // 영화 제목에 작은따옴표(')가 있는 경우 두 개('')로 이스케이프
    // 예: "Ocean's 11" → "Ocean''s 11"
    // 이유: SQL 쿼리에서 문자열을 작은따옴표로 감싸므로 충돌 방지
    $title2 = str_replace("'", "''", $title);
    
    // ===== 영화 기본 정보 출력 =====
    print "<TR><TD>$title<TD>{$year}년<TD>{$length}분<TD>$producer<TD>$boss";
    
    // ===== 출연 배우 정보 처리 =====
    if($actor_cnt > 0){
        // 배우가 있는 경우
        print "<TD>{$actor_cnt}명<TD>";
        
        // ----- 출연 배우 조회 서브 쿼리 -----
        // 이 영화에 출연한 배우들을 나이 어린 순으로 조회
        // (birthdate 내림차순 = 최근 태어난 순 = 나이 어린 순)
        $actor_s = oci_parse($conn,
            "select ms.name from starsin si, moviestar ms ".
            // WHERE 절: 현재 영화에 출연하고, 배우 정보 조인
            "where si.movietitle = '$title2' and si.movieyear = $year ".
            "and si.starname = ms.name ".
            // 나이 어린 배우부터 출력 (최신 트렌드 반영)
            "order by ms.birthdate desc");
        
        if(!$actor_s) p_error($conn);
        
        // 배우 쿼리 실행
        $actor_r = oci_execute($actor_s);
        if(!$actor_r) p_error($actor_s);
        
        // ----- 배우 이름 출력 (콤마로 구분) -----
        $first = true;  // 첫 번째 배우인지 플래그
        
        while($actor = oci_fetch_array($actor_s)){
            // 첫 번째 배우가 아니면 앞에 콤마 출력
            if($first) 
                $first = false;  // 첫 배우 처리 완료
            else 
                print ", ";      // 두 번째 배우부터 콤마 추가
            
            print $actor[0];     // 배우 이름 출력
        }
        
        // 배우 쿼리 리소스 해제
        oci_free_statement($actor_s);
        
    } else {
        // 배우가 없는 경우
        print "<TD>정보없음<TD>정보없음";
    }
    
    // 행 종료
    print "</TR>\n";
}

// ===== 테이블 종료 =====
print "</TABLE>\n";

// ===== 리소스 정리 =====
// 메인 쿼리 리소스 해제
oci_free_statement($stmt);

// 데이터베이스 연결 종료
oci_close($conn);

?>

<!-- 
========================================
페이지 출력 예시:
========================================

영화 목록 테이블:
+----------+------+----------+--------+----------+------------+------------------+
| 제목     | 연도 | 상영시간 | 제작자 | 영화사사장| 출연배우수 | 출연배우진       |
+----------+------+----------+--------+----------+------------+------------------+
| Avatar   | 2009년| 162분   | James  | John     | 3명        | Sam, Zoe, Sigourney |
| Titanic  | 1997년| 195분   | James  | John     | 2명        | Kate, Leonardo   |
| Matrix   | 1999년| 136분   | Larry  | Mike     | 정보없음   | 정보없음         |
+----------+------+----------+--------+----------+------------+------------------+

========================================
주요 기능:
========================================
1. 영화 정보 전체 조회 (제목, 연도, 시간, 제작자, 사장)
2. 각 영화의 출연 배우 수 계산 (서브쿼리 사용)
3. 배우 목록을 나이 어린 순으로 출력
4. SQL 인젝션 방지 (작은따옴표 이스케이프)
5. 중첩 쿼리로 상세 정보 조회 (배우 목록)
6. 적절한 리소스 관리 (statement 해제, 연결 종료)

========================================
보안 고려사항:
========================================
- SQL 인젝션 방지를 위한 문자열 이스케이프 처리
- 향후 개선: Prepared Statement 사용 권장
- XSS 방지를 위한 htmlentities() 사용 (에러 메시지)

========================================
-->
