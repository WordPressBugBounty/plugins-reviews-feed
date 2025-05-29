import { useContext, useEffect, useRef, useState } from "react";
import SbUtils from "../../../../../Utils/SbUtils";
import { __ } from "@wordpress/i18n";
import Button from "../../../../Common/Button";
import Checkbox from "../../../../Common/Checkbox";
import Select from "../../../../Common/Select";

const ReviewsSubmissionsList = (props) => {
	const currentContext = SbUtils.getCurrentContext();
	const {
		curCl,
		sbCustomizer,
		editorTopLoader,
		editorNotification,
		currentCollPageRef,
		collectionRv
	} = useContext( currentContext );

	const checkReviewInCollection = (review) => {
		const usedIn = review?.usedIn ?? [];
		alert(review?.usedIn)
		return usedIn.includes(curCl?.currentCollection?.account_id)
	}

	// Add or remove Submission from Collection
	const [selectedSubmissions, setSelectedSubmissions]= useState([])
	const selectedSubRef = useRef(selectedSubmissions)
	useEffect(() => {
        selectedSubRef.current = selectedSubmissions
    }, [ selectedSubmissions ]);


	const [selectedLoadingIds, setSelectedLoadingIds]= useState([])
	const [selectedBulkAction, setSelectedBulkAction]= useState('')
	const [bulkLoading, setBulkLoading]= useState(false)


	//Handle Add + Remove Subnissions
	const addRemoveFromCollection = (submissionId = undefined, type = 'addToCollection') => {
		let IDS = submissionId !== undefined ? [submissionId] : selectedSubRef.current;

		setSelectedLoadingIds([...IDS])
		if (IDS.length > 0) {
			const page = props?.currentPage > 0 ? props?.currentPage - 1 : 0;
			const currentArchivedPage = props?.currentArchivedPage > 0 ? props?.currentArchivedPage - 1 : 0;

			const formData = {
				action 	: 'sbr_feed_add_remove_submissions',
				type 	: type,
				IDS		: JSON.stringify(IDS),
				collectionAccountID: curCl?.currentCollection?.account_id,
				submissionPage 	: page,
           		collectionPageNumber 	: currentCollPageRef.current,
				archivedPage : currentArchivedPage
			},
			notificationsContent = {
				success : {
					icon : 'success',
					text : type === 'addToCollection' ?
					 __( 'Reviews added from collection', 'reviews-feed' ) :
					 __( 'Reviews removed from collection', 'reviews-feed' )
				}
			};
			SbUtils.ajaxPost(
				sbCustomizer.ajaxHandler,
				formData,
				( data ) => { //Call Back Function
					props?.setAjaxResponseData(
						data?.submissionsList,
						page,
						data?.archivedList,
						currentArchivedPage,
						data?.submissionsCount,
						data?.archivedCount
					)
					setSelectedLoadingIds([])
					setSelectedSubmissions([])
 					selectedSubRef.current = [];
					setSelectedBulkAction('')
					setBulkLoading(false)

					if (data?.postsList) {
						let parsedPostList = data?.postsList.map(element => {
							element = JSON.parse(element.json_data)
							return element;
						});
						collectionRv.setCollectionReviewsList(parsedPostList);
					}
				},
				editorTopLoader,
				editorNotification,
				notificationsContent
			)
		}

	}

	const selectAllSubmissions = () => {
		if (
			selectedSubRef.current.length ===
			props.reviewsSubmissionsList.length &&
			selectedSubmissions.length > 0
		) {
			setSelectedSubmissions([])
			selectedSubRef.current = [];
		} else {
			const ids = props.reviewsSubmissionsList.map(elem => elem.submission_id)
			setSelectedSubmissions([...ids])
			selectedSubRef.current = [...ids];
		}
	}


	//Handle Archive Submission in Collection
	const archiveSubmissionCollection = (submissionId = undefined) => {
		let IDS = submissionId !== undefined ? [submissionId] : selectedSubRef.current;
		if (IDS.length > 0) {
			const page = props?.currentPage > 0 ? props?.currentPage - 1 : 0;
			const currentArchivedPage = props?.currentArchivedPage > 0 ? props?.currentArchivedPage - 1 : 0;
			const formData = {
				action 	: 'sbr_feed_archive_submissions',
				IDS		: JSON.stringify(IDS),
				collectionAccountID: curCl?.currentCollection?.account_id,
				submissionPage 	: page,
           		collectionPageNumber 	: currentCollPageRef.current,
				archivedPage : currentArchivedPage
			},
			notificationsContent = {
				success : {
					icon : 'success',
					text : __( 'Reviews archived from collection', 'reviews-feed' )
				}
			};
			SbUtils.ajaxPost(
				sbCustomizer.ajaxHandler,
				formData,
				( data ) => { //Call Back Function
					props?.setAjaxResponseData(
						data?.submissionsList,
						page,
						data?.archivedList,
						currentArchivedPage,
						data?.submissionsCount,
						data?.archivedCount
					)
					setSelectedLoadingIds([])
					setSelectedSubmissions([])
 					selectedSubRef.current = [];
					setSelectedBulkAction('')
					setBulkLoading(false)

					if (data?.postsList) {
						let parsedPostList = data?.postsList.map(element => {
							element = JSON.parse(element.json_data)
							return element;
						});
						collectionRv.setCollectionReviewsList(parsedPostList);
					}
				},
				editorTopLoader,
				editorNotification,
				notificationsContent
			)
		}

	}


	//Bulk Actions

	const bulkActionOptions = [
		{
			value : '',
			label : __('Bulk Actions','reviews-feed')
		},
		{
			value : 'addToCollection',
			label : __('Add To collection','reviews-feed')
		},
		{
			value : 'removeFromCollection',
			label : __('Remove from collection','reviews-feed')
		},
		{
			value : 'deleteSubmissions',
			label : __('Delete Submissions','reviews-feed')
		},
	];

	const applyBulkAction = () => {
		if (SbUtils.checkNotEmpty(selectedBulkAction)) {
			setBulkLoading(true)
			switch (selectedBulkAction) {
				case 'deleteSubmissions':
					archiveSubmissionCollection();
					break;
				case 'addToCollection':
				case 'removeFromCollection':
					addRemoveFromCollection(undefined, selectedBulkAction);
					break;
				default :
					return false;
			}
		}
	}


	return (
		<section className='sb-formsub-ctn sb-fs'>
			{
				props?.loadingReviews === true &&
				<span className='sb-formsub-load sb-standard-p sb-dark2-text sb-fs'>
					{__('Loading connected forms submissions...', 'reviews-list')}
				</span>
			}
			{
				props.reviewsSubmissionsList.length <= 0  && props?.loadingReviews === false &&
				<div className="sb-formsub-empty sb-fs sb-svg-i sb-svg-p">
					<div className="sb-formsub-empty-content">
						<div className="sb-formsub-empty-icon">
							{SbUtils.printIcon('award_star', false, false, 35)}
						</div>
						<div className="sb-formsub-empty-txt">
							<strong>{__('Form Submissions','reviews-feed')}</strong>
							<span>{__('When your users submit reviews from a connected form, it will show up here.','reviews-feed')}</span>
						</div>
					</div>
				</div>
			}
			{
				props.reviewsSubmissionsList.length > 0 &&
				<div className='sb-collection-list-ins sb-fs'>
					<div
						className='sb-submissions-top-actions sb-fs'
					>
						<div className="sb-submissions-top-bulk">
							<Select
								size='medium'
								value={selectedBulkAction}
								onChange={(event) => {
									setSelectedBulkAction(event.target.value)
								}}
							>
								{
									bulkActionOptions.map(act=> {
										return (
											<option key={act?.value} value={act?.value}>{act?.label}</option>
										)
									})
								}
							</Select>
							{
								SbUtils.checkNotEmpty(selectedBulkAction) &&
								selectedSubmissions.length > 0 &&
								<Button
									iconSize='12'
									icon={ bulkLoading === true ? 'loader' : ''}
									loading={ bulkLoading === true }
									size='medium'
									type='secondary'
									onClick={ () => {
										applyBulkAction()
									}}
									text={ __( 'Apply', 'sb-customizer' ) }
								/>
							}
						</div>
						<div className='sb-submissions-pagination'>
							{
								SbUtils.checkPreviousPaginationButton(props?.currentPage, props?.submissionsCount) &&
								<Button
									type='secondary'
									size='small'
									iconSize='7'
									icon='chevron-left'
									onClick={ () => {
										props.refreshList(-1)
									} }
								/>
							}
							<span className='sb-small-p'>
								{SbUtils.paginationText(props.reviewsSubmissionsList.length, props?.submissionsCount)}
							</span>
							{
								SbUtils.checkNextPaginationButton(props?.currentPage, props?.submissionsCount) &&
								<Button
									type='secondary'
									size='small'
									iconSize='7'
									icon='chevron-right'
									onClick={ () => {
										props.refreshList(1)
									} }
								/>
							}

						</div>
					</div>
					<div className='sb-moderation-list-ctn sb-fs'>
						<div className='sb-moderation-list-head sb-fs'>
							<div className="moderation-list-head-left">
								 <Checkbox
                                    value={ selectedSubRef.current.length === props.reviewsSubmissionsList.length }
                                    enabled={ true }
                                    onChange={ () => {
                                        selectAllSubmissions()
                                    } }
                                />
								<span className='sb-text-small sb-dark2-text'> { __( 'Reviews', 'reviews-feed' ) } </span>
							</div>
							<Button
								type='secondary'
								size='small'
								iconSize='17'
								icon='reset'
								tooltip={ __( 'Refresh List', 'reviews-feed' ) }
								onClick={ () => {
									props.refreshList(0)
								} }
							/>
						</div>
						<div className='sb-moderation-list sb-fs'>
							{
								props.reviewsSubmissionsList.map( (submission, pKey) => {
									const post = SbUtils.getSubmissionReviewData(submission);
									const isUsed = checkReviewInCollection(post);
									return (
										<div
											className='sb-moderation-rev-item sb-fs'
											key={ pKey }
										>
											<span className='sb-moderation-rev-item-date sb-dark2-text'></span>
											<div className='sb-moderation-rev-item-info'>
												<Checkbox
													value={ selectedSubRef.current.includes(submission.submission_id) }
													enabled={ true }
													onChange={ () => {
														const newA = SbUtils.updateArray(submission.submission_id, selectedSubRef.current);
														setSelectedSubmissions(newA)
														selectedSubRef.current = newA;
													}}
												/>
												<div className='sb-moderation-rev-item-info-top'>
													{ SbUtils.printIcon( post?.rating + 'stars', 'sb-item-rating-icon') }
												</div>
												<div className="sb-moderation-rev-txt">
													<strong className='sb-text-small sb-bold'>
														{ post?.title && post?.title.slice(0, 40) + ( post?.title.length > 40 ? '...' : '' )}
														{ (!post?.title && post?.text) && post.text.slice(0, 40) + ( post.text.length > 40 ? '...' : '' )}
													</strong>
													<span className='sb-text-small sb-light-text2 sb-fs'>
														{ post?.text }
													</span>
												</div>
											</div>
											<div className='sb-collection-rev-actions'>
												<div className='sb-feed-item-btns'>
													<div className='sb-relative'>
														<Button
															type={isUsed === true ? 'primary' : 'secondary'}
															size='small'
															iconSize='12'
															boxshadow={false}
															icon={
																selectedLoadingIds.includes(submission.submission_id) ? 'loader' :
																(isUsed === true ? 'minus' : 'plus')
															}
															text={
																isUsed === true ?
																__( 'Remove from Collection', 'reviews-feed' ) :
																__( 'Add to Collection', 'reviews-feed' )
															}
															loading={
																selectedLoadingIds.includes(submission.submission_id) ?
																true : false
															}
															onClick={ () => {
																const type = isUsed === true ? 'removeFromCollection' : 'addToCollection'
																addRemoveFromCollection(submission.submission_id, type)
															} }
															custadsomClass='sb-feed-action-btn sb-feed-edit-btn'
														/>
													</div>
													<div className='sb-relative'>
														<Button
															type='secondary'
															size='small'
															iconSize='15'
															boxshadow={false}
															icon='eye'
															tooltip={ __( 'View', 'reviews-feed' ) }
															onClick={ () => {
																SbUtils.openSubmissionDetails(submission, sbCustomizer)
															} }
															customClass='sb-feed-action-btn sb-feed-edit-btn'
														/>
													</div>
													<div className='sb-relative'>
														<Button
															type='secondary'
															size='small'
															iconSize='11'
															boxshadow={false}
															icon='trash'
															tooltip={ __( 'Delete', 'reviews-feed' ) }
															onClick={ () => {
																archiveSubmissionCollection(submission.submission_id)
															} }
															customClass='sb-feed-action-btn sb-feed-delete-btn'
														/>
													</div>
												</div>
											</div>
										</div>
									)
								} )
							}
						</div>
					</div>
				</div>
			}
		</section>
	)
}

export default ReviewsSubmissionsList;