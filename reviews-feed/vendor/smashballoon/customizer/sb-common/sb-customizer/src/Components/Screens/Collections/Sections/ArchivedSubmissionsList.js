import { useContext, useEffect, useRef, useState } from "react";
import SbUtils from "../../../../../Utils/SbUtils";
import { __ } from "@wordpress/i18n";
import Button from "../../../../Common/Button";
import Checkbox from "../../../../Common/Checkbox";
import Select from "../../../../Common/Select";

const ArchivedSubmissionsList = (props) => {
	const currentContext = SbUtils.getCurrentContext();
	const {
		curCl,
		sbCustomizer,
		editorTopLoader,
		editorConfirmDialog,
		editorNotification,
		currentCollPageRef,
		collectionRv
	} = useContext( currentContext );

	// Add or remove Submission from Collection
	const [selectedArchived, setSelectedArchived]= useState([])
	const selectedSubRef = useRef(selectedArchived)
	useEffect(() => {
        selectedSubRef.current = selectedArchived
    }, [ selectedArchived ]);

	const [selectedLoadingIds, setSelectedLoadingIds]= useState([])
	const [selectedBulkAction, setSelectedBulkAction]= useState('')
	const [bulkLoading, setBulkLoading]= useState(false)

	const selectAllArchives = () => {
		if (
			selectedSubRef.current.length ===
			props.reviewsArchivedList.length &&
			selectedArchived.length > 0
		) {
			setSelectedArchived([])
			selectedSubRef.current = [];
		} else {
			const ids = props.reviewsArchivedList.map(elem => elem.submission_id)
			setSelectedArchived([...ids])
			selectedSubRef.current = [...ids];
		}
	}

	//Handle unArchive Submission in Collection
	const unArchiveSubmissionCollection = (submissionId = undefined) => {
		let IDS = submissionId !== undefined ? [submissionId] : selectedSubRef.current;
		if (IDS.length > 0) {
			const page = props?.currentPage > 0 ? props?.currentPage - 1 : 0;
			const currentArchivedPage = props?.currentArchivedPage > 0 ? props?.currentArchivedPage - 1 : 0;
			setSelectedLoadingIds([...IDS])

			const formData = {
				action 	: 'sbr_feed_archive_submissions',
				IDS		: JSON.stringify(IDS),
				collectionAccountID: curCl?.currentCollection?.account_id,
				submissionPage 	: page,
           		collectionPageNumber 	: currentCollPageRef.current,
				archivedPage : currentArchivedPage,
				type 	: 'unarchive',
			},
			notificationsContent = {
				success : {
					icon : 'success',
					text : __( 'Reviews Unarchived from collection', 'reviews-feed' )
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

	const deleteSubmissionReview = (submissionId = undefined) => {
		let IDS = submissionId !== undefined ? [submissionId] : selectedSubRef.current;
		if (IDS.length > 0) {
			const page = props?.currentPage > 0 ? props?.currentPage - 1 : 0;
			const currentArchivedPage = props?.currentArchivedPage > 0 ? props?.currentArchivedPage - 1 : 0;

			const confirmDialogInfo = {
					active : true,
					heading : `${ __('Delete Submissions.', 'reviews-feed')}`,
					description : __( 'Are you sure you want to permanently delete submissions from your collection?', 'reviews-feed' ),
					confirm : {}
			},
			notificationsContent = {
				success : {
					icon : 'success',
					text : __( 'Reviews Deleted!', 'reviews-feed' )
				}
			};

			confirmDialogInfo.confirm.onConfirm = () => {
				const formData = {
					action 	: 'sbr_feed_delete_submissions',
					IDS		: JSON.stringify(IDS),
					collectionAccountID: curCl?.currentCollection?.account_id,
					submissionPage 	: page,
           			collectionPageNumber 	: currentCollPageRef.current,
					archivedPage : currentArchivedPage
				}
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
			editorConfirmDialog.setConfirmDialog( confirmDialogInfo )
			}
	}

	//Bulk Actions

	const bulkActionOptions = [
		{
			value : '',
			label : __('Bulk Actions','reviews-feed')
		},
		{
			value : 'unarchiveSubmissions',
			label : __('Unarchive Submissions','reviews-feed')
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
				case 'unarchiveSubmissions':
					unArchiveSubmissionCollection();
					break;
				case 'deleteSubmissions':
					deleteSubmissionReview();
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
					{__('Loading collections archived submissions...', 'reviews-list')}
				</span>
			}
			{
				props.reviewsArchivedList.length <= 0  && props?.loadingReviews === false &&
				<div className="sb-formsub-empty sb-fs sb-svg-i sb-svg-p">
					<div className="sb-formsub-empty-content">
						<div className="sb-formsub-empty-icon">
							{SbUtils.printIcon('archive', false, false, 35)}
						</div>
						<div className="sb-formsub-empty-txt">
							<strong>{__('Archived Submissions','reviews-feed')}</strong>
							<span>{__('When you archive a submission, it will be displayed here.','reviews-feed')}</span>
						</div>
					</div>
				</div>
			}
			{
				props.reviewsArchivedList.length > 0 &&
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
								selectedArchived.length > 0 &&
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
								SbUtils.checkPreviousPaginationButton(props?.currentArchivedPage, props?.archivedCount) &&
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
								{SbUtils.paginationText(props.reviewsArchivedList.length, props?.archivedCount)}
							</span>
							{
								SbUtils.checkNextPaginationButton(props?.currentArchivedPage, props?.archivedCount) &&
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
                                    value={ selectedSubRef.current.length === props.reviewsArchivedList.length }
                                    enabled={ true }
                                    onChange={ () => {
                                        selectAllArchives( 'all' )
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
								props?.reviewsArchivedList.map( (submission, pKey) => {
									const post = SbUtils.getSubmissionReviewData(submission);
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
														setSelectedArchived(newA)
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
															type={'secondary'}
															size='small'
															iconSize='12'
															boxshadow={false}
															icon={
																selectedLoadingIds.includes(submission.submission_id) ?
																'loader' : 'unarchive'
															}
															text={__( 'Unarchive', 'reviews-feed' )}
															loading={
																selectedLoadingIds.includes(submission.submission_id) ?
																true : false
															}
															onClick={ () => {
																unArchiveSubmissionCollection(submission.submission_id)

															} }
															custadsomClass='sb-feed-action-btn sb-feed-edit-btn'
														/>
													</div>
													<div className='sb-relative'>
														<Button
															type='secondary'
															size='small'
															iconSize='11'
															boxshadow={false}
															icon='trash'
															tooltip={ __( 'Permanently Delete', 'reviews-feed' ) }
															onClick={ () => {
																deleteSubmissionReview(submission.submission_id)
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

export default ArchivedSubmissionsList;