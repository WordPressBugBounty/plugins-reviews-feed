import { __ } from "@wordpress/i18n";
import SbUtils from "../../../../../Utils/SbUtils";
import { useContext, useEffect, useMemo, useRef, useState } from "react";
import EmptyState from "./EmptyState";
import ModalContainer from "../../../../Global/ModalContainer";
import FormsSubmissionModal from "../../../../Modals/FormsSubmissionModal";
import SubmissionsSettings from "./SubmissionsSettings";
import ReviewsSubmissionsList from "./ReviewsSubmissionsList";
import ArchivedSubmissionsList from "./ArchivedSubmissionsList";

const FormsSubmissionHome = () => {
	const currentContext = SbUtils.getCurrentContext();
	const {
		sbCustomizer,
		curCl,
		forms,
		currentForms,
        slSection,
		subFrModal,
		formSubScreen,
		editorTopLoader,
		editorNotification,
		currentCollPageRef,
		collectionRv
    } = useContext( currentContext );



	const menuItems = [
		{
			id : 'submissionsList',
			heading : __('Form Submissions', 'reviews-feed')
		},
		{
			id : 'archivedList',
			heading : __('Archived Submissions', 'reviews-feed')
		},
		{
			id : 'settings',
			heading : __('Settings', 'reviews-feed')
		}
	];



	// Submissions List
	const [reviewsSubmissionsList, setReviewsSubmissionsList] = useState([]);

	const revSubmissionListRef = useRef(reviewsSubmissionsList)
    useEffect(() => {
        revSubmissionListRef.current = reviewsSubmissionsList
    }, [ reviewsSubmissionsList ]);

	const [ submissionsPage, setSubmissionsPage ] = useState(0);
	const revSubmissionsPageRef = useRef(submissionsPage)
	useEffect(() => {
        revSubmissionsPageRef.current = submissionsPage
    }, [ submissionsPage ]);

	//Archived List
	const [reviewsArchivedList, setReviewsArchivedList] = useState([]);

	const revArchivedListRef = useRef(reviewsArchivedList)
    useEffect(() => {
        revArchivedListRef.current = reviewsArchivedList
    }, [ reviewsArchivedList ]);

	const [ archivedPage, setArchivedPage ] = useState(0);
	const revArchivedPageRef = useRef(archivedPage)
	useEffect(() => {
        revArchivedPageRef.current = archivedPage
    }, [ archivedPage ]);



	//Loading Different Reviews List // Used & Archived
	const [ loadingReviews, setLoadingReviews ] = useState( false );

	//Number of Submissions Count
	const [submissionsCount, setSubmissionsCount] = useState(0);
	const [archivedCount, setArchivedCount] = useState(0);


	const getCurrentCollectionFormSubmissions = () => {
		setLoadingReviews(true)
		const formData = {
			action 					: 'sbr_feed_submissions_list',
			collectionAccountID		: curCl?.currentCollection?.account_id,
            collectionPageNumber 	: currentCollPageRef.current,
			submissionPage 			: revSubmissionsPageRef.current,
			archivedPage 			: revArchivedPageRef.current
		},
		notificationsContent = {
			success : {
				icon : 'success',
				text : __( 'Reviews list updated successfully', 'reviews-feed' )
			}
		}
		SbUtils.ajaxPost(
			sbCustomizer.ajaxHandler,
			formData,
			( data ) => { //Call Back Function
				if (data?.submissionsList) {
					let newData = revSubmissionListRef.current.concat(data?.submissionsList)
					setReviewsSubmissionsList(newData)
					revSubmissionListRef.current = newData;
				}

				if (data?.archivedList) {
					let newArchivedData = revArchivedListRef.current.concat(data?.archivedList)
					setReviewsArchivedList(newArchivedData)
					revArchivedListRef.current = newArchivedData;
				}

				if (data?.submissionsCount) {
					setSubmissionsCount(data?.submissionsCount)
				}
				if (data?.archivedCount) {
					setArchivedCount(data?.archivedCount)
				}
				if (data?.postsList) {
                    let parsedPostList = data?.postsList.map(element => {
                        element = JSON.parse(element.json_data)
                        return element;
                    });
                    collectionRv.setCollectionReviewsList(parsedPostList);
                }
				setLoadingReviews(false)
			},
			editorTopLoader,
			editorNotification,
			notificationsContent
		)
	}

	useEffect(() => {
		formSubScreen.setCurrentView(
			currentForms?.currentConnectedForms.length > 0
			? 'submissionsList'
			: 'empty'
		);
		getCurrentCollectionFormSubmissions()
  	}, []);


	const refreshSubmissionsList = (page = 0, archivedPage = 0) => {
		let checkFirstPage = page === 0 ?
							0 : parseInt(revSubmissionsPageRef.current + page); //Either We add new Page or we Set to 0

		let newPage = page === null ?
					revSubmissionsPageRef.current - 1 : checkFirstPage;

		setSubmissionsPage(newPage < 0 ? 0 : newPage)
		revSubmissionsPageRef.current = newPage;


		setReviewsSubmissionsList([])
		revSubmissionListRef.current  = [];

		//Archivces
		let checkArchiveFirstPage = archivedPage === 0 ?
									0 : parseInt(revArchivedPageRef.current + archivedPage);

		let newArchivedPage = archivedPage === null ?
							revArchivedPageRef.current - 1 : checkArchiveFirstPage;

		setArchivedPage(newArchivedPage < 0 ? 0 : newArchivedPage)
		revArchivedPageRef.current = newArchivedPage;

		setReviewsArchivedList([])
		revArchivedListRef.current  = [];

		getCurrentCollectionFormSubmissions()
	}


	const setNewLoadedValues = (
		respSubmissionsList,
		respSubmissionsPage,
		respArchivedList,
		respArchivedPage,
		submissionsCount,
		archivedCount
	) => {
		setReviewsSubmissionsList(respSubmissionsList)
		revSubmissionListRef.current  = respSubmissionsList;
		setSubmissionsPage(respSubmissionsPage)
		revSubmissionsPageRef.current = respSubmissionsPage;

		setReviewsArchivedList(respArchivedList)
		revArchivedListRef.current  = respArchivedList;
		setArchivedPage(respArchivedPage)
		revArchivedPageRef.current = respArchivedPage;

		setSubmissionsCount(submissionsCount)
		setArchivedCount(archivedCount)
	}

	return (
		<>
			<section className='sb-full-wrapper sb-fs'>
				<section className='sb-small-wrapper'>
					<div className='sb-scollection-top sb-sbform-subm-top sb-fs'>
						<div className='sb-scollection-heading-ctn'>
							<strong
								className='sb-scollection-heading-back sb-small-p sb-bold sb-svg-i'
								onClick={() => {
									slSection.setCollectionSectionActive('single')
								}}
							>
								{SbUtils.printIcon('chevron-left', '', false, 7)}
								{__('Back to','reviews-feed')} { curCl?.currentCollection?.name }
							</strong>
							<h2 className='sb-scollection-heading sb-h2'>
								{__('Form Submissions','reviews-feed')}
							</h2>
						</div>
						{
							formSubScreen?.currentView !== 'empty' &&
							<div className='sb-formsub-top-links sb-fs'>
								{
									menuItems.map((menItem, mIKey) => {
										return (
											<div
												className='sb-formsub-top-link-item'
												key={mIKey}
												data-active={menItem.id === formSubScreen?.currentView}
												onClick={() => {
													formSubScreen?.setCurrentView(menItem.id)
												}}
											>
												{menItem.heading}
											</div>
										)
									})
								}
							</div>
						}
					</div>
					{
						formSubScreen?.currentView === 'empty' &&
						<EmptyState/>
					}
					{
						formSubScreen?.currentView === 'submissionsList' &&
						<ReviewsSubmissionsList
							reviewsSubmissionsList={revSubmissionListRef.current}
							loadingReviews={loadingReviews}
							currentPage={revSubmissionsPageRef.current}
							currentArchivedPage={revArchivedPageRef.current}
							submissionsCount={submissionsCount}
							archivedCount={archivedCount}
							setAjaxResponseData={(
								respSubmissionsList,
								respSubmissionsPage,
								respArchivedList,
								respArchivedPage,
								submissionsCount,
								archivedCount
							) => {
								setNewLoadedValues(
									respSubmissionsList,
									respSubmissionsPage,
									respArchivedList,
									respArchivedPage,
									submissionsCount,
									archivedCount
								)
							}}
							refreshList={(page) => {
								refreshSubmissionsList(page, null)
							}}
						/>
					}
					{
						formSubScreen?.currentView === 'archivedList' &&
						<ArchivedSubmissionsList
							reviewsArchivedList={revArchivedListRef.current}
							loadingReviews={loadingReviews}
							currentPage={revSubmissionsPageRef.current}
							currentArchivedPage={revArchivedPageRef.current}
							submissionsCount={submissionsCount}
							archivedCount={archivedCount}
							setAjaxResponseData={(
								respSubmissionsList,
								respSubmissionsPage,
								respArchivedList,
								respArchivedPage,
								submissionsCount,
								archivedCount
							) => {
								setNewLoadedValues(
									respSubmissionsList,
									respSubmissionsPage,
									respArchivedList,
									respArchivedPage,
									submissionsCount,
									archivedCount
								)
							}}

							refreshList={(page) => {
								refreshSubmissionsList(null, page)
							}}

						/>
					}
					{
						formSubScreen?.currentView === 'settings' &&
						<SubmissionsSettings
							loadingReviews={loadingReviews}
							currentPage={revSubmissionsPageRef.current}
							currentArchivedPage={revArchivedPageRef.current}
							setAjaxResponseData={(
								respSubmissionsList,
								respSubmissionsPage,
								respArchivedList,
								respArchivedPage,
								submissionsCount,
								archivedCount
							) => {
								setNewLoadedValues(
									respSubmissionsList,
									respSubmissionsPage,
									respArchivedList,
									respArchivedPage,
									submissionsCount,
									archivedCount
								)
							}}
						/>
					}

				</section>
			</section>
			{
				subFrModal?.formSubmissionModalActive === true &&
				<ModalContainer
					size='small'
					closebutton={true}
					onClose={ () => {
						subFrModal?.setFormSubmissionModalActive(false)
					} }
				>
					<FormsSubmissionModal
						loadingReviews={loadingReviews}
						currentPage={revSubmissionsPageRef.current}
						currentArchivedPage={revArchivedPageRef.current}
						setAjaxResponseData={(
							respSubmissionsList,
							respSubmissionsPage,
							respArchivedList,
							respArchivedPage,
							submissionsCount,
							archivedCount
						) => {
							setNewLoadedValues(
								respSubmissionsList,
								respSubmissionsPage,
								respArchivedList,
								respArchivedPage,
								submissionsCount,
								archivedCount
							)
						}}
					/>
				</ModalContainer>
			}
		</>
	)

}

export default FormsSubmissionHome;